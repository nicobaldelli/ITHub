<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Support;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Support\ContainerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base para tests de integración: levanta el container REAL
 * (config/container.php) con SQLite en memoria y el esquema de TestSchema.
 *
 * Cada test recibe un container y una base nuevos, así que no hay estado
 * compartido entre tests. El logger se reemplaza por NullLogger para no
 * escribir en storage/logs.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ContainerInterface $container;
    protected Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $basePath = dirname(__DIR__, 2);
        $definitions = require $basePath . '/config/container.php';
        $definitions['settings'] = TestSettings::get();
        $definitions['basePath'] = $basePath;
        $definitions[LoggerInterface::class] = static fn (): LoggerInterface => new NullLogger();

        $builder = new ContainerBuilder();
        $builder->addDefinitions($definitions);
        $this->container = $builder->build();
        ContainerProvider::set($this->container);

        // Boot de Eloquent (setAsGlobal) + esquema
        $this->capsule = $this->container->get(Capsule::class);
        $this->capsule->getConnection()->getSchemaBuilder()->enableForeignKeyConstraints();
        TestSchema::create($this->capsule->getConnection()->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        $this->capsule->getConnection()->disconnect();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Fecha Y-m-d relativa a hoy (ej: -1 = ayer). */
    protected function fecha(int $diasDesdeHoy = 0): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $diasDesdeHoy)));
    }

    /**
     * Request PSR-7 mínimo, como el que arma Slim: con REMOTE_ADDR y User-Agent
     * (los servicios los usan para auditoría y refresh tokens).
     */
    protected function makeRequest(
        string $method = 'POST',
        string $path = '/api/v1/test',
        string $ip = '127.0.0.1',
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', 'phpunit');
    }

    // ------------------------------------------------------------
    // Factories: insertan por query builder con TODAS las columnas NOT NULL,
    // devuelven el id. Los tests leen con el modelo Eloquent que corresponda.
    // ------------------------------------------------------------

    /** @param array<string,mixed> $override */
    protected function crearUsuario(array $override = []): int
    {
        static $n = 0;
        $n++;
        $row = array_merge([
            'nombre' => 'Test',
            'apellido' => 'User ' . $n,
            'email' => "user{$n}@test.local",
            'password_hash' => password_hash('Password-Segura-1!', PASSWORD_BCRYPT, ['cost' => 4]),
            'rol' => 'admin',
            'activo' => 1,
            'must_change_password' => 0,
            'failed_login_attempts' => 0,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $override);

        return (int) Capsule::table('users')->insertGetId($row);
    }

    /** @param array<string,mixed> $override */
    protected function crearCliente(array $override = []): int
    {
        static $n = 0;
        $n++;
        $row = array_merge([
            'razon_social' => "Cliente {$n} SA",
            'cuit' => self::cuitValido($n),
            'activo' => 1,
            'plazo_pago_default' => 30,
            'banco' => 'Banco Test',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $override);

        return (int) Capsule::table('clientes')->insertGetId($row);
    }

    /** @param array<string,mixed> $override */
    protected function crearServicio(int $clienteId, int $userId, array $override = []): int
    {
        $row = array_merge([
            'cliente_id' => $clienteId,
            'tipo' => 'mantenimiento',
            'nombre' => 'Mantenimiento mensual',
            'moneda' => 'ARS',
            'importe_base' => 1000.00,
            'iva_porcentaje' => 21.00,
            'tipo_factura_default' => 'A',
            'fecha_inicio' => $this->fecha(-1),
            'modo_facturacion' => 'mes_calendario',
            'dia_facturacion' => 1,
            'estado' => 'activo',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $override);

        return (int) Capsule::table('servicios')->insertGetId($row);
    }

    /** @param array<string,mixed> $override */
    protected function crearCuota(int $servicioId, int $numero, string $fechaPrevista, array $override = []): int
    {
        $row = array_merge([
            'servicio_id' => $servicioId,
            'numero_cuota' => $numero,
            'importe' => 1000.00,
            // Se guarda como 'Y-m-d' a propósito: en SQLite la comparación de
            // fechas es textual y las columnas date de las migraciones no
            // llevan hora.
            'fecha_prevista' => $fechaPrevista,
            'estado' => 'pendiente',
            'etiqueta' => "Cuota {$numero}",
            'es_proporcional' => 0,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $override);

        return (int) Capsule::table('servicio_cuotas')->insertGetId($row);
    }

    /**
     * CUIT sintácticamente válido (con checksum AFIP) distinto por índice.
     * Prefijo 30 (persona jurídica) + 8 dígitos derivados de $n.
     */
    protected static function cuitValido(int $n): string
    {
        $cuerpo = str_pad((string) (10000000 + $n), 8, '0', STR_PAD_LEFT);
        $base = '30' . $cuerpo;
        $mult = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $base[$i] * $mult[$i];
        }
        $dv = 11 - ($suma % 11);
        if ($dv === 11) {
            $dv = 0;
        } elseif ($dv === 10) {
            // Este cuerpo no tiene DV válido con prefijo 30: usamos el siguiente
            return self::cuitValido($n + 1000);
        }
        return "30-{$cuerpo}-{$dv}";
    }
}
