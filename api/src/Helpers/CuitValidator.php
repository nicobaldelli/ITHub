<?php

declare(strict_types=1);

namespace ITHub\Api\Helpers;

/**
 * Validador de CUIT/CUIL argentino con checksum.
 *
 * Formato aceptado: con o sin guiones. Se normaliza a "XX-XXXXXXXX-X".
 *
 * Algoritmo del checksum (AFIP):
 *  - 11 dígitos
 *  - Multiplica los primeros 10 por [5,4,3,2,7,6,5,4,3,2]
 *  - Suma todos los productos
 *  - 11 - (suma % 11)
 *  - Si el resultado es 11 → dígito verificador = 0
 *  - Si el resultado es 10 → CUIT inválido
 *  - Sino → dígito verificador = el resultado
 */
final class CuitValidator
{
    private const MULTIPLIERS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(string $cuit): bool
    {
        $digits = preg_replace('/\D/', '', $cuit) ?? '';
        if (strlen($digits) !== 11) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $digits[$i] * self::MULTIPLIERS[$i];
        }
        $mod = $sum % 11;
        $expected = 11 - $mod;
        if ($expected === 11) {
            $expected = 0;
        } elseif ($expected === 10) {
            return false;
        }

        return (int) $digits[10] === $expected;
    }

    /**
     * Devuelve el CUIT en formato canónico XX-XXXXXXXX-X.
     */
    public static function normalize(string $cuit): string
    {
        $digits = preg_replace('/\D/', '', $cuit) ?? '';
        if (strlen($digits) !== 11) {
            return $cuit;
        }
        return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10, 1);
    }
}
