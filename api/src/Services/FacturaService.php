<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ForbiddenException;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\FacturaArchivo;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\ClienteRepository;
use ITHub\Api\Repositories\FacturaRepository;
use ITHub\Api\Validators\FacturaValidator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Lógica de negocio de facturas.
 *
 * Reglas de permiso (resource-level, no se confía en el frontend):
 *  - admin: todo
 *  - ventas: crea; edita solo las propias y no cobradas
 *  - cobranzas: solo edita campos de cobranza; marca check_cobranza
 *  - visualizador: read only
 */
final class FacturaService
{
    /** Campos que cobranzas puede editar (defensa en profundidad). */
    private const CAMPOS_COBRANZA = [
        'total_cobrado', 'fecha_pago', 'banco', 'observaciones',
    ];

    public function __construct(
        private readonly FacturaRepository $facturaRepo,
        private readonly ClienteRepository $clienteRepo,
        private readonly AuditoriaService $audit,
        private readonly GoogleDriveService $drive,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data, User $user, ServerRequestInterface $request): FacturaVenta
    {
        $clean = FacturaValidator::validate($data, isUpdate: false);

        // Cliente debe existir
        $cliente = $this->clienteRepo->findById((int) $clean['cliente_id']);
        if ($cliente === null) {
            throw new ValidationException('Cliente no encontrado', ['cliente_id' => 'no existe']);
        }
        if (!$cliente->activo) {
            throw new ValidationException('Cliente inactivo', ['cliente_id' => 'inactivo']);
        }

        // Único por numero_factura
        if ($this->facturaRepo->existsByNumero($clean['numero_factura'])) {
            throw new ValidationException('Ya existe una factura con ese número',
                ['numero_factura' => 'duplicado']);
        }

        // Regla de negocio: toda factura debe asociarse a una cuota de servicio.
        // El bot facturarCuota del backend la setea automaticamente; el alta
        // manual via /facturas/nueva tiene que recibirla del cliente.
        if (empty($clean['servicio_cuota_id'])) {
            throw new ValidationException(
                'La factura debe estar asociada a una cuota de servicio',
                ['servicio_cuota_id' => 'requerido']
            );
        }

        // Verificar que esa cuota no tenga ya otra factura activa
        $existeFacturaPrevia = FacturaVenta::where('servicio_cuota_id', $clean['servicio_cuota_id'])
            ->whereNull('deleted_at')
            ->exists();
        if ($existeFacturaPrevia) {
            throw new ValidationException(
                'Esa cuota ya tiene una factura activa. Archivá la existente para reemplazarla.',
                ['servicio_cuota_id' => 'cuota ya facturada']
            );
        }

        // Autocálculo importe_total_pesos si no vino y es ARS o si hay TDC en USD
        if (!isset($clean['importe_total_pesos']) || $clean['importe_total_pesos'] === null) {
            $clean['importe_total_pesos'] = $this->calcularImporteTotalPesos($clean);
        }

        $clean['created_by'] = $user->id;
        $clean['updated_by'] = $user->id;
        $clean['estado'] = $clean['estado'] ?? FacturaVenta::ESTADO_EMITIDA;

        $factura = FacturaVenta::create($clean);

        $this->audit->log($user->id, 'factura', $factura->id, Auditoria::ACCION_CREAR, [
            'numero_factura' => $factura->numero_factura,
            'cliente_id' => $factura->cliente_id,
            'importe_total_pesos' => $factura->importe_total_pesos,
        ], $request);

        return $factura->fresh(['cliente']);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data, User $user, ServerRequestInterface $request): FacturaVenta
    {
        $factura = $this->facturaRepo->findById($id);
        if ($factura === null) {
            throw new NotFoundException('Factura no encontrada');
        }

        // Resource-level checks (NO confiar en el frontend)
        $clean = $this->enforceFieldPermissions($factura, $data, $user);

        $clean = FacturaValidator::validate($clean, isUpdate: true);

        // Si cambió numero_factura, validar unicidad
        if (isset($clean['numero_factura']) && $clean['numero_factura'] !== $factura->numero_factura) {
            if ($this->facturaRepo->existsByNumero($clean['numero_factura'], excludeId: $id)) {
                throw new ValidationException('Ya existe otra factura con ese número',
                    ['numero_factura' => 'duplicado']);
            }
        }

        $before = $factura->only(array_keys($clean));
        $factura->fill($clean);
        $factura->updated_by = $user->id;
        $factura->save();

        $this->audit->log($user->id, 'factura', $factura->id, Auditoria::ACCION_EDITAR, [
            'before' => $before,
            'after' => $factura->only(array_keys($clean)),
        ], $request);

        return $factura->fresh(['cliente']);
    }

    public function toggleCheckCobranza(int $id, User $user, ServerRequestInterface $request): FacturaVenta
    {
        $factura = $this->facturaRepo->findById($id);
        if ($factura === null) {
            throw new NotFoundException('Factura no encontrada');
        }

        $newValue = !$factura->check_cobranza;
        $factura->check_cobranza = $newValue;
        $factura->check_cobranza_user_id = $newValue ? $user->id : null;
        $factura->check_cobranza_fecha = $newValue ? date('Y-m-d H:i:s') : null;
        $factura->updated_by = $user->id;

        if ($newValue) {
            $factura->estado = FacturaVenta::ESTADO_COBRADA;
            // Si no se cargó fecha de pago, asumir hoy
            if ($factura->fecha_pago === null) {
                $factura->fecha_pago = date('Y-m-d');
            }
            // Si no se cargó total_cobrado, asumir el total
            if ((float) $factura->total_cobrado === 0.0) {
                $factura->total_cobrado = $factura->importe_total_pesos;
            }
        } else {
            $factura->estado = $this->recalcularEstado($factura);
        }
        $factura->save();

        $this->audit->log($user->id, 'factura', $factura->id, Auditoria::ACCION_MARCAR_COBRADA, [
            'value' => $newValue,
        ], $request);

        return $factura->fresh(['cliente']);
    }

    /**
     * Marca una factura como enviada al cliente. Requerido obligatoriamente:
     *  - numero_factura definitivo (no puede empezar con "AUTO-")
     *  - fecha_factura (fecha de emisión)
     *  - fecha_envio
     *  - archivo PDF de la factura emitida
     *  - tdc, si la factura es USD y todavía no tiene
     *
     * El PDF se sube a Drive en la ruta:
     *   <root>/<cliente>/<año>/<mes>/<numero_factura>.pdf
     * Si las carpetas no existen, se crean. Drive debe estar configurado
     * (drive_root_folder_id en config_app + service-account.json).
     *
     * @param array{numero_factura?:string, fecha_factura?:string, fecha_envio?:string, tdc?:float|string|null} $data
     */
    public function marcarEnviada(
        int $id,
        array $data,
        ?UploadedFileInterface $pdf,
        User $user,
        ServerRequestInterface $request,
    ): FacturaVenta {
        $factura = $this->facturaRepo->findById($id);
        if ($factura === null) {
            throw new NotFoundException('Factura no encontrada');
        }

        if ($factura->fecha_envio !== null) {
            throw new ValidationException(
                'La factura ya está marcada como enviada',
                ['fecha_envio' => 'ya tiene fecha de envío']
            );
        }

        $numero = isset($data['numero_factura']) ? trim((string) $data['numero_factura']) : '';
        $fechaFactura = isset($data['fecha_factura']) ? (string) $data['fecha_factura'] : '';
        $fechaEnvio = isset($data['fecha_envio']) ? (string) $data['fecha_envio'] : '';

        $errors = [];

        if ($numero === '' || str_starts_with($numero, 'AUTO-')) {
            $errors['numero_factura'] = $numero === ''
                ? 'Requerido'
                : 'No puede empezar con "AUTO-" — usá el número definitivo de la factura emitida';
        } elseif (mb_strlen($numero) > 50) {
            $errors['numero_factura'] = 'Máximo 50 caracteres';
        } elseif ($numero !== $factura->numero_factura
            && $this->facturaRepo->existsByNumero($numero, excludeId: $id)) {
            $errors['numero_factura'] = 'Ya existe otra factura con ese número';
        }

        if ($fechaFactura === '' || !self::isValidDate($fechaFactura)) {
            $errors['fecha_factura'] = 'Requerida (YYYY-MM-DD)';
        }
        if ($fechaEnvio === '' || !self::isValidDate($fechaEnvio)) {
            $errors['fecha_envio'] = 'Requerida (YYYY-MM-DD)';
        }

        // Si la factura es USD y no tiene TDC, el admin lo carga acá
        $tdcEntrante = null;
        if ($factura->moneda === 'USD' && ($factura->tdc === null || (float) $factura->tdc <= 0)) {
            $rawTdc = $data['tdc'] ?? null;
            if ($rawTdc === null || $rawTdc === '' || !is_numeric($rawTdc) || (float) $rawTdc <= 0) {
                $errors['tdc'] = 'Requerido para facturas en USD (TDC del día > 0)';
            } else {
                $tdcEntrante = (float) $rawTdc;
            }
        }

        // PDF obligatorio
        if ($pdf === null || $pdf->getError() === UPLOAD_ERR_NO_FILE) {
            $errors['archivo'] = 'Archivo PDF de la factura requerido';
        } elseif ($pdf->getError() !== UPLOAD_ERR_OK) {
            $errors['archivo'] = 'Error al subir el archivo (codigo ' . $pdf->getError() . ')';
        } else {
            // Validar mime PDF con magic bytes
            $stream = $pdf->getStream();
            $head = $stream->read(8);
            $stream->rewind();
            // PDF empieza con "%PDF-"
            if (!str_starts_with($head, '%PDF-')) {
                $errors['archivo'] = 'El archivo debe ser un PDF válido';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos para marcar como enviada', $errors);
        }

        // Verificar que Drive esté disponible ANTES de hacer cambios
        if (!$this->drive->isAvailable()) {
            throw new ValidationException(
                'Google Drive no está configurado — no se puede subir el PDF. '
                . 'Configurá drive_root_folder_id en /configuracion y el service account JSON.',
                ['archivo' => 'drive no disponible']
            );
        }

        $before = [
            'numero_factura' => $factura->numero_factura,
            'fecha_factura' => $factura->fecha_factura?->format('Y-m-d'),
            'fecha_envio' => $factura->fecha_envio?->format('Y-m-d'),
            'tdc' => $factura->tdc,
            'importe_total_pesos' => $factura->importe_total_pesos,
        ];

        // 1. Subir PDF a Drive PRIMERO (si falla, no tocamos la factura).
        //    El cliente snapshot se toma de la factura en su momento de creación.
        $clienteSnapshot = $factura->cliente?->razon_social ?? 'cliente';
        $fechaParaCarpeta = \DateTimeImmutable::createFromFormat('Y-m-d', $fechaFactura)
            ?: new \DateTimeImmutable();

        $driveResult = $this->drive->uploadFacturaArchivo(
            $clienteSnapshot,
            $fechaParaCarpeta,
            $pdf->getClientFilename() ?? 'factura.pdf',
            'application/pdf',
            $pdf->getSize() ?? 0,
            $pdf->getStream()->getContents(),
            forcedFileName: $numero . '.pdf',
        );

        // 2. Transacción: crear FacturaArchivo + actualizar factura
        return Capsule::connection()->transaction(function () use (
            $factura, $numero, $fechaFactura, $fechaEnvio, $tdcEntrante,
            $user, $request, $before, $driveResult, $pdf
        ): FacturaVenta {
            // Crear adjunto vinculado a la factura
            FacturaArchivo::create([
                'factura_id' => $factura->id,
                'drive_file_id' => $driveResult['drive_file_id'],
                'nombre_archivo' => $numero . '.pdf',
                'mime_type' => $driveResult['mime_type'],
                'tamanio_bytes' => $driveResult['tamanio_bytes'],
                'drive_view_url' => $driveResult['drive_view_url'],
                'drive_download_url' => $driveResult['drive_download_url'],
                'uploaded_by' => $user->id,
            ]);

            // Actualizar factura
            $factura->numero_factura = $numero;
            $factura->fecha_factura = $fechaFactura;
            $factura->fecha_envio = $fechaEnvio;

            if ($tdcEntrante !== null) {
                $factura->tdc = $tdcEntrante;
                $factura->importe_total_pesos = round(
                    (float) $factura->importe_con_iva * $tdcEntrante, 2,
                );
            }

            $factura->updated_by = $user->id;
            $factura->save();

            $this->audit->log($user->id, 'factura', $factura->id, Auditoria::ACCION_EDITAR, [
                'accion' => 'marcar_enviada',
                'before' => $before,
                'after' => [
                    'numero_factura' => $factura->numero_factura,
                    'fecha_factura' => $factura->fecha_factura?->format('Y-m-d'),
                    'fecha_envio' => $factura->fecha_envio?->format('Y-m-d'),
                    'tdc' => $factura->tdc,
                    'importe_total_pesos' => $factura->importe_total_pesos,
                ],
                'archivo' => $numero . '.pdf',
                'drive_file_id' => $driveResult['drive_file_id'],
            ], $request);

            return $factura->fresh(['cliente']);
        });
    }

    private static function isValidDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($d === false) return false;
        $year = (int) $d->format('Y');
        return $year >= 1900 && $year <= 2100;
    }

    public function delete(int $id, User $user, ServerRequestInterface $request): void
    {
        $factura = $this->facturaRepo->findById($id);
        if ($factura === null) {
            throw new NotFoundException('Factura no encontrada');
        }

        $factura->delete(); // soft delete

        $this->audit->log($user->id, 'factura', $id, Auditoria::ACCION_ELIMINAR, [
            'soft_delete' => true,
            'numero_factura' => $factura->numero_factura,
        ], $request);
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * Aplica las reglas de permiso server-side y devuelve el subset permitido del payload.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function enforceFieldPermissions(FacturaVenta $factura, array $data, User $user): array
    {
        return match ($user->rol) {
            User::ROL_ADMIN => $data,

            User::ROL_VENTAS => $this->enforceVentasPermissions($factura, $data, $user),

            User::ROL_COBRANZAS => array_intersect_key(
                $data,
                array_flip(self::CAMPOS_COBRANZA)
            ),

            default => throw new ForbiddenException(),
        };
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function enforceVentasPermissions(FacturaVenta $factura, array $data, User $user): array
    {
        if ($factura->created_by !== $user->id) {
            throw new ForbiddenException('Solo podés editar facturas que vos creaste');
        }
        if ($factura->check_cobranza) {
            throw new ForbiddenException('No se puede editar una factura ya cobrada');
        }
        // No permitir tocar campos de cobranza desde ventas
        $blocked = ['total_cobrado', 'check_cobranza', 'check_cobranza_user_id', 'check_cobranza_fecha', 'fecha_pago'];
        foreach ($blocked as $f) {
            unset($data[$f]);
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function calcularImporteTotalPesos(array $data): float
    {
        $importeConIva = (float) ($data['importe_con_iva'] ?? 0);
        $moneda = $data['moneda'] ?? 'ARS';

        if ($moneda === 'USD' && isset($data['tdc']) && $data['tdc'] > 0) {
            return round($importeConIva * (float) $data['tdc'], 2);
        }
        return round($importeConIva, 2);
    }

    private function recalcularEstado(FacturaVenta $factura): string
    {
        if ($factura->estado === FacturaVenta::ESTADO_ANULADA
            || $factura->estado === FacturaVenta::ESTADO_BORRADOR) {
            return $factura->estado;
        }
        if ($factura->check_cobranza) {
            return FacturaVenta::ESTADO_COBRADA;
        }
        if ($factura->vencimiento !== null && $factura->vencimiento->getTimestamp() < strtotime(date('Y-m-d'))) {
            return FacturaVenta::ESTADO_VENCIDA;
        }
        return FacturaVenta::ESTADO_EMITIDA;
    }
}
