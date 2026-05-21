<?php

declare(strict_types=1);

namespace ITHub\Api\Validators;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Helpers\CuitValidator;
use ITHub\Api\Models\FacturaVenta;

/**
 * Valida y normaliza el payload de creación/edición de facturas.
 */
final class FacturaValidator
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // numero_factura (requerido en create)
        if (!$isUpdate || array_key_exists('numero_factura', $data)) {
            $num = trim((string) ($data['numero_factura'] ?? ''));
            if ($num === '' || mb_strlen($num) > 50) {
                $errors['numero_factura'] = 'Requerido, hasta 50 caracteres';
            }
        }

        // cliente_id (requerido en create)
        if (!$isUpdate || array_key_exists('cliente_id', $data)) {
            if (!isset($data['cliente_id']) || !is_numeric($data['cliente_id']) || (int) $data['cliente_id'] <= 0) {
                $errors['cliente_id'] = 'Requerido y numérico positivo';
            }
        }

        // tipo
        if (!$isUpdate || array_key_exists('tipo', $data)) {
            if (!isset($data['tipo']) || !in_array($data['tipo'], FacturaVenta::TIPOS, true)) {
                $errors['tipo'] = 'Requerido. Permitidos: ' . implode(', ', FacturaVenta::TIPOS);
            }
        }

        // cuit (snapshot, requerido en create con checksum)
        if (!$isUpdate || array_key_exists('cuit', $data)) {
            $cuit = (string) ($data['cuit'] ?? '');
            if ($cuit === '' || !CuitValidator::isValid($cuit)) {
                $errors['cuit'] = 'CUIT inválido';
            }
        }

        // moneda
        if (array_key_exists('moneda', $data)) {
            if (!in_array($data['moneda'], FacturaVenta::MONEDAS, true)) {
                $errors['moneda'] = 'Permitidos: ' . implode(', ', FacturaVenta::MONEDAS);
            }
        }

        // Importes (no negativos)
        foreach (['importe_sin_iva', 'importe_con_iva', 'importe_total_pesos', 'retenciones', 'total_cobrado'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '') {
                if (!is_numeric($data[$f]) || (float) $data[$f] < 0) {
                    $errors[$f] = 'Debe ser un número >= 0';
                }
            }
        }

        // tdc
        if (array_key_exists('tdc', $data) && $data['tdc'] !== null && $data['tdc'] !== '') {
            if (!is_numeric($data['tdc']) || (float) $data['tdc'] <= 0) {
                $errors['tdc'] = 'Debe ser > 0';
            }
        }

        // Si moneda=USD, tdc es requerido y > 0
        if (isset($data['moneda']) && $data['moneda'] === 'USD') {
            if (!isset($data['tdc']) || !is_numeric($data['tdc']) || (float) $data['tdc'] <= 0) {
                $errors['tdc'] = 'Requerido cuando moneda=USD';
            }
        }

        // Fechas
        foreach (['fecha_factura', 'fecha_envio', 'vencimiento', 'fecha_pago'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '') {
                if (!self::isValidDate((string) $data[$f])) {
                    $errors[$f] = 'Fecha inválida (formato YYYY-MM-DD)';
                }
            }
        }
        if (!$isUpdate && (!isset($data['fecha_factura']) || $data['fecha_factura'] === '')) {
            $errors['fecha_factura'] = 'Requerida';
        }

        // numero_mes 1-12
        if (array_key_exists('numero_mes', $data) && $data['numero_mes'] !== null && $data['numero_mes'] !== '') {
            $nm = (int) $data['numero_mes'];
            if ($nm < 1 || $nm > 12) {
                $errors['numero_mes'] = 'Debe estar entre 1 y 12';
            }
        }

        // plazo_pago
        if (array_key_exists('plazo_pago', $data) && $data['plazo_pago'] !== null && $data['plazo_pago'] !== '') {
            $pp = (int) $data['plazo_pago'];
            if ($pp < 0 || $pp > 3650) {
                $errors['plazo_pago'] = 'Entre 0 y 3650';
            }
        }

        // CBU
        if (array_key_exists('cbu', $data) && $data['cbu'] !== null && $data['cbu'] !== '') {
            $cbu = preg_replace('/\D/', '', (string) $data['cbu']) ?? '';
            if (strlen($cbu) !== 22) {
                $errors['cbu'] = '22 dígitos requeridos';
            }
        }

        // Emails
        foreach (['mail_envio_factura', 'mail_gestion_cobranza'] as $f) {
            if (isset($data[$f]) && $data[$f] !== '' && !filter_var($data[$f], FILTER_VALIDATE_EMAIL)) {
                $errors[$f] = 'Email inválido';
            }
        }

        // estado (opcional, si viene debe ser válido)
        if (array_key_exists('estado', $data) && $data['estado'] !== null && $data['estado'] !== '') {
            if (!in_array($data['estado'], FacturaVenta::ESTADOS, true)) {
                $errors['estado'] = 'Permitidos: ' . implode(', ', FacturaVenta::ESTADOS);
            }
        }

        // observaciones / detalle anti-script
        foreach (['observaciones', 'detalle_factura'] as $f) {
            if (isset($data[$f]) && is_string($data[$f])
                && preg_match('/<\s*(script|iframe|object|embed)\b/i', $data[$f])) {
                $errors[$f] = 'Contenido no permitido';
            }
        }

        // Coherencia: total_cobrado no debe superar el importe_total_pesos si ambos vienen
        if (isset($data['total_cobrado']) && isset($data['importe_total_pesos'])
            && is_numeric($data['total_cobrado']) && is_numeric($data['importe_total_pesos'])
            && (float) $data['total_cobrado'] > (float) $data['importe_total_pesos'] + 0.01) {
            $errors['total_cobrado'] = 'No puede superar el importe total';
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos', $errors);
        }

        // Normalización
        $clean = [];
        $stringFields = [
            'numero_factura', 'tipo', 'cuit_pais', 'moneda', 'detalle_factura',
            'mes_cubierto', 'banco', 'alias', 'direccion',
            'mail_envio_factura', 'contacto_envio_factura', 'telefono_contacto_proveedores',
            'mail_gestion_cobranza', 'contacto_gestion_cobranza', 'telefono_contacto_cobranza',
            'observaciones', 'estado',
        ];
        foreach ($stringFields as $f) {
            if (array_key_exists($f, $data)) {
                $v = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
                $clean[$f] = ($v === '' ? null : $v);
            }
        }

        if (array_key_exists('cuit', $data)) {
            $clean['cuit'] = CuitValidator::normalize((string) $data['cuit']);
        }
        if (array_key_exists('cbu', $data)) {
            $clean['cbu'] = ($data['cbu'] === null || $data['cbu'] === '')
                ? null
                : preg_replace('/\D/', '', (string) $data['cbu']);
        }
        if (array_key_exists('cliente_id', $data)) {
            $clean['cliente_id'] = (int) $data['cliente_id'];
        }
        if (array_key_exists('servicio_cuota_id', $data)) {
            $clean['servicio_cuota_id'] = $data['servicio_cuota_id'] === null || $data['servicio_cuota_id'] === ''
                ? null : (int) $data['servicio_cuota_id'];
        }
        foreach (['importe_sin_iva', 'importe_con_iva', 'importe_total_pesos', 'retenciones', 'total_cobrado', 'tdc'] as $f) {
            if (array_key_exists($f, $data)) {
                $clean[$f] = ($data[$f] === null || $data[$f] === '') ? null : (float) $data[$f];
            }
        }
        foreach (['numero_mes', 'plazo_pago'] as $f) {
            if (array_key_exists($f, $data)) {
                $clean[$f] = ($data[$f] === null || $data[$f] === '') ? null : (int) $data[$f];
            }
        }
        foreach (['fecha_factura', 'fecha_envio', 'vencimiento', 'fecha_pago'] as $f) {
            if (array_key_exists($f, $data)) {
                $clean[$f] = ($data[$f] === null || $data[$f] === '') ? null : (string) $data[$f];
            }
        }

        return $clean;
    }

    private static function isValidDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($d === false) {
            return false;
        }
        $year = (int) $d->format('Y');
        return $year >= 1900 && $year <= 2100;
    }
}
