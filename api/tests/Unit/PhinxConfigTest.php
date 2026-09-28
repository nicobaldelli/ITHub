<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * db/phinx.php elige el usuario de migraciones: DB_MIGRATE_USER si está
 * definido Y no vacío, sino el de runtime. El bug original era usar `??`,
 * que no cae al fallback cuando phpdotenv deja la variable en ''.
 */
final class PhinxConfigTest extends TestCase
{
    private const CLAVES = ['DB_USER', 'DB_PASS', 'DB_MIGRATE_USER', 'DB_MIGRATE_PASS', 'DB_HOST', 'DB_NAME', 'DB_PORT', 'APP_ENV'];

    /** @var array<string,mixed> */
    private array $backup = [];

    protected function setUp(): void
    {
        foreach (self::CLAVES as $k) {
            $this->backup[$k] = $_ENV[$k] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CLAVES as $k) {
            if ($this->backup[$k] === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $this->backup[$k];
            }
        }
    }

    /** @return array<string,mixed> */
    private function cargar(array $env): array
    {
        $_ENV = array_merge($_ENV, [
            'APP_ENV' => 'production',
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'ithub_test',
            'DB_PORT' => '3306',
        ], $env);

        return require dirname(__DIR__, 2) . '/db/phinx.php';
    }

    public function testConMigrateUserVacioUsaElUsuarioRuntime(): void
    {
        $cfg = $this->cargar([
            'DB_USER' => 'ithub_app',
            'DB_PASS' => 'runtime-pass',
            'DB_MIGRATE_USER' => '',
            'DB_MIGRATE_PASS' => '',
        ]);

        self::assertSame('ithub_app', $cfg['environments']['production']['user']);
        self::assertSame('runtime-pass', $cfg['environments']['production']['pass']);
    }

    public function testConMigrateUserDefinidoLoUsaConSuPropiaPassword(): void
    {
        $cfg = $this->cargar([
            'DB_USER' => 'ithub_app',
            'DB_PASS' => 'runtime-pass',
            'DB_MIGRATE_USER' => 'ithub_migrate',
            'DB_MIGRATE_PASS' => 'migrate-pass',
        ]);

        self::assertSame('ithub_migrate', $cfg['environments']['production']['user']);
        self::assertSame('migrate-pass', $cfg['environments']['production']['pass']);
    }

    public function testMigrateUserDefinidoConPasswordVaciaNoHeredaLaDeRuntime(): void
    {
        // Si alguien define el user de migraciones pero no su password, es un
        // error de configuración: no hay que "arreglarlo" con la de runtime.
        $cfg = $this->cargar([
            'DB_USER' => 'ithub_app',
            'DB_PASS' => 'runtime-pass',
            'DB_MIGRATE_USER' => 'ithub_migrate',
            'DB_MIGRATE_PASS' => '',
        ]);

        self::assertSame('ithub_migrate', $cfg['environments']['production']['user']);
        self::assertSame('', $cfg['environments']['production']['pass']);
    }

    public function testSinNingunaVariableDeMigracionUsaRuntime(): void
    {
        unset($_ENV['DB_MIGRATE_USER'], $_ENV['DB_MIGRATE_PASS']);
        $cfg = $this->cargar(['DB_USER' => 'solo_runtime', 'DB_PASS' => 'p']);

        self::assertSame('solo_runtime', $cfg['environments']['production']['user']);
        self::assertSame('p', $cfg['environments']['production']['pass']);
    }

    public function testElEntornoPorDefectoSaleDeAppEnvYLosPathsApuntanADb(): void
    {
        $cfg = $this->cargar(['DB_USER' => 'u', 'DB_PASS' => 'p', 'APP_ENV' => 'staging']);

        self::assertSame('staging', $cfg['environments']['default_environment']);
        self::assertStringEndsWith('/db/migrations', $cfg['paths']['migrations']);
        self::assertStringEndsWith('/db/seeds', $cfg['paths']['seeds']);
        self::assertSame('phinxlog', $cfg['environments']['default_migration_table']);
    }
}
