<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use ITHub\Api\Exceptions\ForbiddenException;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\ClienteRepository;
use ITHub\Api\Repositories\FacturaRepository;
use ITHub\Api\Validators\FacturaValidator;
use Psr\Http\Message\ServerRequestInterface;

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
        private readonly AuditoriaService $audit
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
