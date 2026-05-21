<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ForbiddenException;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;
use ITHub\Api\Models\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Acciones sobre cuotas individuales del cronograma de un servicio.
 *
 * Endpoints que delega este service:
 *  - editar (admin): fecha_prevista, importe, etiqueta, observaciones
 *  - omitir / cancelar (admin): cambio de estado
 *  - facturar (admin + ventas): genera factura precargada y vincula
 *
 * Reglas:
 *  - Solo cuotas en estado=pendiente se pueden modificar
 *  - Al facturar: el servicio debe estar activo, el cliente activo
 *  - El usuario aporta numero_factura + tipo (+ tdc si USD); el resto se autoFill
 *  - Al pasar todas las cuotas a "resueltas" → el servicio se autocompleta
 */
final class ServicioCuotaService
{
    /** Campos editables de una cuota individual (admin) */
    private const CAMPOS_EDITABLES = [
        'fecha_prevista', 'importe', 'etiqueta', 'observaciones',
    ];

    public function __construct(
        private readonly FacturaService $facturaService,
        private readonly AuditoriaService $audit
    ) {
    }

    // ============================================================
    // EDITAR cuota
    // ============================================================
    public function editar(
        int $servicioId,
        int $cuotaId,
        array $data,
        User $user,
        ServerRequestInterface $request
    ): ServicioCuota {
        $cuota = $this->resolveCuota($servicioId, $cuotaId);
        if (!$cuota->esEditable()) {
            throw new ValidationException(
                'Solo se pueden editar cuotas pendientes',
                ['estado' => $cuota->estado]
            );
        }

        // Whitelist de campos
        $clean = [];
        foreach (self::CAMPOS_EDITABLES as $f) {
            if (array_key_exists($f, $data)) {
                $clean[$f] = $data[$f];
            }
        }

        // Validaciones puntuales
        if (isset($clean['fecha_prevista'])) {
            if (!self::isDate((string) $clean['fecha_prevista'])) {
                throw new ValidationException('Fecha inválida', ['fecha_prevista' => 'YYYY-MM-DD']);
            }
        }
        if (isset($clean['importe'])) {
            if (!is_numeric($clean['importe']) || (float) $clean['importe'] < 0) {
                throw new ValidationException('Importe inválido', ['importe' => 'debe ser >= 0']);
            }
            $clean['importe'] = (float) $clean['importe'];
        }
        if (isset($clean['etiqueta']) && mb_strlen((string) $clean['etiqueta']) > 100) {
            throw new ValidationException('Etiqueta muy larga', ['etiqueta' => 'max 100']);
        }

        $before = $cuota->only(array_keys($clean));
        $cuota->fill($clean);
        $cuota->save();

        $this->audit->log(
            $user->id,
            'servicio_cuota',
            $cuota->id,
            Auditoria::ACCION_EDITAR,
            ['before' => $before, 'after' => $cuota->only(array_keys($clean))],
            $request
        );

        return $cuota->fresh();
    }

    // ============================================================
    // OMITIR / CANCELAR cuota
    // ============================================================
    public function omitir(int $servicioId, int $cuotaId, User $user, ServerRequestInterface $request): ServicioCuota
    {
        return $this->cambiarEstado($servicioId, $cuotaId, ServicioCuota::ESTADO_OMITIDA, $user, $request);
    }

    public function cancelar(int $servicioId, int $cuotaId, User $user, ServerRequestInterface $request): ServicioCuota
    {
        return $this->cambiarEstado($servicioId, $cuotaId, ServicioCuota::ESTADO_CANCELADA, $user, $request);
    }

    // ============================================================
    // FACTURAR cuota — el corazón del workflow
    // ============================================================
    /**
     * @param array{numero_factura: string, tipo: string, fecha_factura?: string, vencimiento?: string, tdc?: float|string, ...} $data
     */
    public function facturar(
        int $servicioId,
        int $cuotaId,
        array $data,
        User $user,
        ServerRequestInterface $request
    ): FacturaVenta {
        $cuota = $this->resolveCuota($servicioId, $cuotaId);

        if ($cuota->estado !== ServicioCuota::ESTADO_PENDIENTE) {
            throw new ValidationException(
                'La cuota no está en estado pendiente',
                ['estado' => $cuota->estado]
            );
        }

        $servicio = $cuota->servicio;
        if ($servicio === null) {
            throw new NotFoundException('Servicio no encontrado');
        }
        if ($servicio->estado !== Servicio::ESTADO_ACTIVO) {
            throw new ValidationException(
                'El servicio no está activo',
                ['estado_servicio' => $servicio->estado]
            );
        }

        $cliente = Cliente::find($servicio->cliente_id);
        if ($cliente === null || !$cliente->activo) {
            throw new ValidationException(
                'El cliente del servicio no está activo',
                ['cliente_id' => 'inactivo o no encontrado']
            );
        }

        if (empty($data['numero_factura'])) {
            throw new ValidationException('numero_factura es requerido', ['numero_factura' => 'requerido']);
        }
        if (empty($data['tipo'])) {
            throw new ValidationException('tipo es requerido', ['tipo' => 'requerido']);
        }

        // TDC obligatorio si servicio en USD (ajustes y conversion siempre se hacen al TDC del día)
        $tdc = null;
        if ($servicio->moneda === 'USD') {
            if (empty($data['tdc']) || !is_numeric($data['tdc']) || (float) $data['tdc'] <= 0) {
                throw new ValidationException(
                    'tdc es requerido para servicios en USD (TDC del día)',
                    ['tdc' => 'requerido > 0']
                );
            }
            $tdc = (float) $data['tdc'];
        }

        // Construir payload de factura
        $importeCuota = (float) $cuota->importe;
        $importeTotalPesos = $tdc !== null ? round($importeCuota * $tdc, 2) : $importeCuota;

        $facturaData = [
            'numero_factura' => trim((string) $data['numero_factura']),
            'cliente_id' => $servicio->cliente_id,
            'tipo' => $data['tipo'],
            'cuit' => $cliente->cuit, // snapshot
            'cuit_pais' => $cliente->cuit_pais,
            'moneda' => $servicio->moneda,
            // Por defecto los importes vienen del importe de la cuota (sin discriminar IVA).
            // El usuario puede editarlos (admin: cualquier valor; ventas: solo accesorios).
            'importe_sin_iva' => $data['importe_sin_iva'] ?? $importeCuota,
            'importe_con_iva' => $data['importe_con_iva'] ?? $importeCuota,
            'importe_total_pesos' => $importeTotalPesos,
            'tdc' => $tdc,
            'retenciones' => $data['retenciones'] ?? 0,
            'total_cobrado' => 0,
            'detalle_factura' => $data['detalle_factura']
                ?? sprintf('%s — %s', $servicio->nombre, $cuota->etiqueta ?? 'Cuota ' . $cuota->numero_cuota),
            'fecha_factura' => $data['fecha_factura'] ?? date('Y-m-d'),
            'vencimiento' => $data['vencimiento'] ?? $this->calcularVencimiento($cliente, $servicio, $data),
            'plazo_pago' => $data['plazo_pago'] ?? $cliente->plazo_pago_default,
            'mes_cubierto' => $data['mes_cubierto'] ?? $cuota->etiqueta,
            'numero_mes' => $data['numero_mes']
                ?? ($cuota->fecha_prevista ? (int) $cuota->fecha_prevista->format('n') : null),
            'banco' => $data['banco'] ?? $cliente->banco,
            'cbu' => $data['cbu'] ?? $cliente->cbu,
            'alias' => $data['alias'] ?? $cliente->alias,
            'direccion' => $data['direccion'] ?? $cliente->direccion,
            'mail_envio_factura' => $cliente->mail_envio_factura,
            'contacto_envio_factura' => $cliente->contacto_envio_factura,
            'telefono_contacto_proveedores' => $cliente->telefono_contacto_proveedores,
            'mail_gestion_cobranza' => $cliente->mail_gestion_cobranza,
            'contacto_gestion_cobranza' => $cliente->contacto_gestion_cobranza,
            'telefono_contacto_cobranza' => $cliente->telefono_contacto_cobranza,
            'estado' => 'emitida',
            'servicio_cuota_id' => $cuota->id,
        ];

        // Si el usuario tiene rol VENTAS, restringimos qué puede sobreescribir.
        if ($user->rol === User::ROL_VENTAS) {
            // Forzar valores críticos a los autocalculados (independiente de lo que mandó)
            $facturaData['cliente_id'] = $servicio->cliente_id;
            $facturaData['cuit'] = $cliente->cuit;
            $facturaData['moneda'] = $servicio->moneda;
            $facturaData['importe_con_iva'] = $importeCuota;
            $facturaData['importe_total_pesos'] = $importeTotalPesos;
            // El resto de campos accesorios pueden venir del payload
        }

        return Capsule::connection()->transaction(function () use ($facturaData, $cuota, $user, $request) {
            // Delegamos la creacion de factura al FacturaService (valida + audita)
            $factura = $this->facturaService->create($facturaData, $user, $request);

            // Vinculamos cuota → factura
            $cuota->factura_id = $factura->id;
            $cuota->estado = ServicioCuota::ESTADO_FACTURADA;
            $cuota->save();

            $this->audit->log(
                $user->id,
                'servicio_cuota',
                $cuota->id,
                Auditoria::ACCION_EDITAR,
                ['accion' => 'facturada', 'factura_id' => $factura->id],
                $request
            );

            $this->autocompletarServicioSiCorresponde($cuota->servicio_id);

            return $factura;
        });
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    private function resolveCuota(int $servicioId, int $cuotaId): ServicioCuota
    {
        $cuota = ServicioCuota::with('servicio')->where('id', $cuotaId)
            ->where('servicio_id', $servicioId)
            ->first();
        if ($cuota === null) {
            throw new NotFoundException('Cuota no encontrada para el servicio indicado');
        }
        return $cuota;
    }

    private function cambiarEstado(
        int $servicioId,
        int $cuotaId,
        string $nuevoEstado,
        User $user,
        ServerRequestInterface $request
    ): ServicioCuota {
        $cuota = $this->resolveCuota($servicioId, $cuotaId);
        if ($cuota->estado !== ServicioCuota::ESTADO_PENDIENTE) {
            throw new ValidationException(
                'Solo se puede cambiar el estado de cuotas pendientes',
                ['estado_actual' => $cuota->estado]
            );
        }
        $estadoAnterior = $cuota->estado;
        $cuota->estado = $nuevoEstado;
        $cuota->save();

        $this->audit->log(
            $user->id,
            'servicio_cuota',
            $cuota->id,
            Auditoria::ACCION_EDITAR,
            ['estado_anterior' => $estadoAnterior, 'estado_nuevo' => $nuevoEstado],
            $request
        );

        $this->autocompletarServicioSiCorresponde($cuota->servicio_id);

        return $cuota->fresh();
    }

    /**
     * Si todas las cuotas del servicio quedan resueltas y el servicio NO es indefinido,
     * cambia el estado a `completado`.
     */
    private function autocompletarServicioSiCorresponde(int $servicioId): void
    {
        $servicio = Servicio::find($servicioId);
        if ($servicio === null || $servicio->estado !== Servicio::ESTADO_ACTIVO) {
            return;
        }
        if ($servicio->esIndefinido()) {
            return; // indefinidos no se autocompletan
        }
        $pendientes = ServicioCuota::where('servicio_id', $servicioId)
            ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
            ->exists();
        if (!$pendientes) {
            $servicio->estado = Servicio::ESTADO_COMPLETADO;
            $servicio->save();
        }
    }

    private function calcularVencimiento(Cliente $cliente, Servicio $servicio, array $data): ?string
    {
        $base = $data['fecha_factura'] ?? date('Y-m-d');
        $plazo = $data['plazo_pago'] ?? $cliente->plazo_pago_default;
        if (!$plazo) {
            return null;
        }
        return date('Y-m-d', strtotime("{$base} + {$plazo} days"));
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($d === false) {
            return false;
        }
        $year = (int) $d->format('Y');
        return $year >= 1900 && $year <= 2100;
    }
}
