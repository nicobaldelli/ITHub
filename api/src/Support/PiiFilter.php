<?php

declare(strict_types=1);

namespace ITHub\Api\Support;

/**
 * Filtro de PII para logs.
 * Enmascara emails, CUITs, passwords, tokens y secrets antes de que lleguen a disco.
 */
final class PiiFilter
{
    /** @var string[] Claves cuyos valores deben enmascararse completamente. */
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'password_confirmation',
        'pwd', 'pass', 'secret', 'token', 'access_token', 'refresh_token',
        'jwt', 'api_key', 'authorization', 'cookie', 'set-cookie',
        'cbu', 'service_account', 'gpg_passphrase',
    ];

    /**
     * Aplica el filtro recursivamente sobre un arreglo de contexto.
     */
    public static function filter(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lk = strtolower((string) $key);

            if (self::isSensitiveKey($lk)) {
                $out[$key] = '***REDACTED***';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::filter($value);
                continue;
            }

            if (is_string($value)) {
                $out[$key] = self::maskString($value);
                continue;
            }

            $out[$key] = $value;
        }
        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Aplica máscaras a strings sueltos (emails, CUIT) cuando viajan como mensajes/contexto.
     */
    private static function maskString(string $value): string
    {
        // Email: ej. juan@empresa.com → j***@empresa.com
        $value = preg_replace_callback(
            '/([A-Za-z0-9._%+-])([A-Za-z0-9._%+-]+)(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            static fn(array $m): string => $m[1] . str_repeat('*', max(3, strlen($m[2]))) . $m[3],
            $value
        ) ?? $value;

        // CUIT: 20-12345678-9 → 20-***-***-9
        $value = preg_replace(
            '/\b(\d{2})-?\d{8}-?(\d)\b/',
            '$1-***-***-$2',
            $value
        ) ?? $value;

        return $value;
    }
}
