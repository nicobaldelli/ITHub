<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Support;

/**
 * Settings reales (config/settings.php, con las env de phpunit.xml) más los
 * ajustes que necesitan los tests. Se carga UNA vez porque settings.php
 * declara funciones (env_get/env_required) y no se puede requerir dos veces.
 */
final class TestSettings
{
    /** @var array<string,mixed>|null */
    private static ?array $base = null;

    /** @return array<string,mixed> */
    public static function get(): array
    {
        if (self::$base === null) {
            self::$base = require dirname(__DIR__, 2) . '/config/settings.php';
        }
        $s = self::$base;

        // SQLite en memoria: cada conexión nueva es una base vacía nueva
        $s['db'] = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        // bcrypt cost 4 en vez de 12: la política real se prueba aparte, acá
        // solo hace lento cada login (y la suite de auth hace muchos)
        $s['security']['bcrypt_cost'] = 4;

        return $s;
    }
}
