<?php

declare(strict_types=1);

namespace ITHub\Api\Validators;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\ServicioAjuste;

/**
 * Valida el payload de creación de un ajuste de tarifa.
 *
 * El usuario aporta UNO de:
 *  - modo='monto'      + valor: nuevo importe absoluto (en la moneda del servicio)
 *  - modo='porcentaje' + valor: variación %, puede ser negativa (ej: -5 = baja 5%)
 *
 * El sistema calcula el otro a partir del importe_base actual del servicio.
 */
final class ServicioAjusteValidator
{
    /**
     * @param array<string,mixed> $data
     * @return array{tipo: string, modo: string, valor: float, fecha_aplicacion: string, cuota_desde_id: ?int, observaciones: ?string}
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $errors = [];

        $tipo = (string) ($data['tipo'] ?? '');
        if (!in_array($tipo, ServicioAjuste::TIPOS, true)) {
            $errors['tipo'] = 'Tipo inválido. Valores: ' . implode(', ', ServicioAjuste::TIPOS);
        }

        $modo = (string) ($data['modo'] ?? '');
        if (!in_array($modo, ['monto', 'porcentaje'], true)) {
            $errors['modo'] = "Debe ser 'monto' o 'porcentaje'";
        }

        if (!isset($data['valor']) || !is_numeric($data['valor'])) {
            $errors['valor'] = 'Requerido y numérico';
        } elseif ($modo === 'monto' && (float) $data['valor'] <= 0) {
            $errors['valor'] = 'Para modo=monto debe ser > 0';
        }
        // Para modo=porcentaje, aceptamos negativos (baja) y positivos (aumento)

        if (empty($data['fecha_aplicacion']) || !self::isDate((string) $data['fecha_aplicacion'])) {
            $errors['fecha_aplicacion'] = 'Requerida (formato YYYY-MM-DD)';
        }

        if (isset($data['cuota_desde_id']) && $data['cuota_desde_id'] !== '' && $data['cuota_desde_id'] !== null) {
            if (!is_numeric($data['cuota_desde_id']) || (int) $data['cuota_desde_id'] <= 0) {
                $errors['cuota_desde_id'] = 'Debe ser entero positivo o null';
            }
        }

        if (isset($data['observaciones']) && is_string($data['observaciones'])
            && preg_match('/<\s*(script|iframe|object|embed)\b/i', $data['observaciones'])) {
            $errors['observaciones'] = 'Contenido no permitido';
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos', $errors);
        }

        return [
            'tipo' => $tipo,
            'modo' => $modo,
            'valor' => (float) $data['valor'],
            'fecha_aplicacion' => (string) $data['fecha_aplicacion'],
            'cuota_desde_id' => isset($data['cuota_desde_id']) && $data['cuota_desde_id'] !== '' && $data['cuota_desde_id'] !== null
                ? (int) $data['cuota_desde_id'] : null,
            'observaciones' => isset($data['observaciones']) && trim((string) $data['observaciones']) !== ''
                ? trim((string) $data['observaciones']) : null,
        ];
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
