<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Repositories\ClienteRepository;
use ITHub\Api\Repositories\FacturaRepository;
use ITHub\Api\Repositories\ServicioRepository;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Filtros y orden por defecto de los listados de clientes, facturas y servicios.
 */
final class RepositoriosFiltrosTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->crearUsuario();
    }

    /** @return int[] ids en el orden devuelto */
    private function ids(iterable $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $out[] = (int) $it->id;
        }
        return $out;
    }

    /** @param array<string,mixed> $override */
    private function crearFactura(int $clienteId, array $override = []): int
    {
        static $n = 0;
        $n++;
        $row = array_merge([
            'numero_factura' => sprintf('0001-%08d', $n),
            'cliente_id' => $clienteId,
            'tipo' => 'A',
            'cuit' => '30-10000001-5',
            'moneda' => 'ARS',
            'importe_sin_iva' => 1000,
            'importe_con_iva' => 1210,
            'importe_total_pesos' => 1210,
            'retenciones' => 0,
            'total_cobrado' => 0,
            'fecha_factura' => $this->fecha(-10),
            'vencimiento' => $this->fecha(20),
            'check_cobranza' => 0,
            'estado' => 'emitida',
            'created_by' => $this->userId,
            'updated_by' => $this->userId,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $override);

        return (int) Capsule::table('facturas_venta')->insertGetId($row);
    }

    // ------------------------------------------------------------
    // Clientes
    // ------------------------------------------------------------

    public function testClientesOrdenDefaultUltimosDadosDeAltaPrimero(): void
    {
        $viejo = $this->crearCliente(['razon_social' => 'Alfa SA', 'created_at' => '2024-01-01 10:00:00']);
        $nuevo = $this->crearCliente(['razon_social' => 'Zeta SA', 'created_at' => '2026-01-01 10:00:00']);
        $medio = $this->crearCliente(['razon_social' => 'Beta SA', 'created_at' => '2025-01-01 10:00:00']);

        $repo = new ClienteRepository();
        $this->assertSame([$nuevo, $medio, $viejo], $this->ids($repo->paginate([], 1, 25)->items()));

        // Sort explícito sigue funcionando
        $porNombre = $repo->paginate(['sort_by' => 'razon_social', 'sort_dir' => 'asc'], 1, 25)->items();
        $this->assertSame([$viejo, $medio, $nuevo], $this->ids($porNombre));

        // Sort inválido cae al default sin romper
        $invalido = $repo->paginate(['sort_by' => 'password; drop table', 'sort_dir' => 'x'], 1, 25)->items();
        $this->assertSame([$nuevo, $medio, $viejo], $this->ids($invalido));
    }

    public function testClientesFiltrosPorCampo(): void
    {
        $a = $this->crearCliente([
            'razon_social' => 'Acme SRL',
            'tipo_default' => 'A',
            'plazo_pago_default' => 30,
            'mail_gestion_cobranza' => 'cobranzas@acme.com',
            'contacto_envio_factura' => 'Juan Perez',
            'direccion' => 'Av. Siempre Viva 123',
            'cuit_pais' => null,
            'activo' => 1,
            'created_at' => '2025-06-15 09:00:00',
        ]);
        $b = $this->crearCliente([
            'razon_social' => 'Globex Corp',
            'tipo_default' => 'E',
            'plazo_pago_default' => 60,
            'mail_envio_factura' => 'facturas@globex.com',
            'contacto_gestion_cobranza' => 'Maria Lopez',
            'cuit_pais' => 'US',
            'activo' => 0,
            'created_at' => '2025-09-01 09:00:00',
        ]);
        $this->crearFactura($a);

        $repo = new ClienteRepository();
        $f = fn (array $filters): array => $this->ids($repo->paginate($filters, 1, 25)->items());

        $this->assertSame([$a], $f(['search' => 'juan']));
        $this->assertSame([$b], $f(['search' => 'globex.com']));
        $this->assertSame([$a], $f(['search' => 'siempre viva']));
        $this->assertSame([$a], $f(['tipo_default' => 'A']));
        $this->assertSame([$b], $f(['cuit_pais' => 'US']));
        $this->assertSame([$b], $f(['plazo_desde' => '45']));
        $this->assertSame([$a], $f(['plazo_hasta' => '45']));
        $this->assertSame([$b], $f(['mail' => 'globex']));
        $this->assertSame([$b], $f(['contacto' => 'lopez']));
        $this->assertSame([$a], $f(['activo' => 'true']));
        $this->assertSame([$b], $f(['activo' => 'false']));
        $this->assertSame([$a], $f(['con_facturas' => 'true']));
        $this->assertSame([$b], $f(['con_facturas' => 'false']));
        $this->assertSame([$b], $f(['alta_desde' => '2025-07-01']));
        $this->assertSame([$a], $f(['alta_hasta' => '2025-06-15']));
        // Tipo fuera de catálogo o fecha inválida se ignoran (no filtran nada)
        $this->assertCount(2, $f(['tipo_default' => 'ZZZ']));
        $this->assertCount(2, $f(['alta_desde' => 'ayer']));
    }

    // ------------------------------------------------------------
    // Facturas
    // ------------------------------------------------------------

    public function testFacturasOrdenDefaultFechaMasRecientePrimero(): void
    {
        $c = $this->crearCliente();
        $vieja = $this->crearFactura($c, ['fecha_factura' => '2025-01-10']);
        $nueva = $this->crearFactura($c, ['fecha_factura' => '2026-03-01']);
        $media = $this->crearFactura($c, ['fecha_factura' => '2025-12-31']);
        // Misma fecha que $nueva: desempata por id desc (la cargada después, primero)
        $nueva2 = $this->crearFactura($c, ['fecha_factura' => '2026-03-01']);

        $repo = new FacturaRepository();
        $this->assertSame([$nueva2, $nueva, $media, $vieja], $this->ids($repo->paginate([], 1, 25)->items()));
    }

    public function testFacturasFiltrosPorCampo(): void
    {
        $acme = $this->crearCliente(['razon_social' => 'Acme SRL']);
        $globex = $this->crearCliente(['razon_social' => 'Globex Corp']);
        $otroUser = $this->crearUsuario();
        $servicioId = $this->crearServicio($acme, $this->userId);
        $cuotaId = $this->crearCuota($servicioId, 1, $this->fecha(-5));

        $f1 = $this->crearFactura($acme, [
            'numero_factura' => '0001-00000100',
            'tipo' => 'A',
            'moneda' => 'ARS',
            'estado' => 'emitida',
            'fecha_factura' => '2026-01-15',
            'vencimiento' => '2026-02-15',
            'importe_total_pesos' => 5000,
            'total_cobrado' => 0,
            'retenciones' => 0,
            'mes_cubierto' => 'Enero 2026',
            'numero_mes' => 1,
            'servicio_cuota_id' => $cuotaId,
            'detalle_factura' => 'Mantenimiento mensual',
        ]);
        $f2 = $this->crearFactura($globex, [
            'numero_factura' => '0002-00000200',
            'tipo' => 'E',
            'moneda' => 'USD',
            'estado' => 'cobrada',
            'fecha_factura' => '2026-03-10',
            'vencimiento' => '2026-04-10',
            'fecha_pago' => '2026-04-01',
            'fecha_envio' => '2026-03-11',
            'tdc' => 1200.5,
            'importe_total_pesos' => 120050,
            'total_cobrado' => 120050,
            'retenciones' => 350,
            'check_cobranza' => 1,
            'created_by' => $otroUser,
            'observaciones' => 'Pago anticipado',
        ]);

        $repo = new FacturaRepository();
        $f = fn (array $filters): array => $this->ids($repo->paginate($filters, 1, 25)->items());

        $this->assertSame([$f1], $f(['cliente_id' => (string) $acme]));
        $this->assertSame([$f2], $f(['tipo' => 'E']));
        $this->assertSame([$f2], $f(['moneda' => 'USD']));
        $this->assertSame([$f1], $f(['estado' => 'emitida']));
        $this->assertSame([$f2], $f(['created_by' => (string) $otroUser]));
        $this->assertSame([$f1], $f(['numero_factura' => '0001']));
        $this->assertSame([$f1], $f(['mes_cubierto' => 'enero']));
        $this->assertSame([$f1], $f(['numero_mes' => '1']));
        $this->assertSame([$f1], $f(['detalle' => 'mantenimiento']));
        $this->assertSame([$f2], $f(['observaciones' => 'anticipado']));

        $this->assertSame([$f2], $f(['fecha_desde' => '2026-02-01']));
        $this->assertSame([$f1], $f(['fecha_hasta' => '2026-02-01']));
        $this->assertSame([$f2], $f(['vencimiento_desde' => '2026-03-01']));
        $this->assertSame([$f2], $f(['pago_desde' => '2026-03-01', 'pago_hasta' => '2026-04-30']));
        $this->assertSame([$f2], $f(['envio_desde' => '2026-03-01']));

        $this->assertSame([$f2], $f(['importe_min' => '10000']));
        $this->assertSame([$f1], $f(['importe_max' => '10000']));
        $this->assertSame([$f2], $f(['importe_min' => '100.000,50'])); // formato AR
        $this->assertSame([$f2], $f(['cobrado_min' => '1']));

        $this->assertSame([$f2], $f(['cobrado' => 'true']));
        $this->assertSame([$f1], $f(['cobrado' => 'false']));
        $this->assertSame([$f2], $f(['con_retenciones' => 'true']));
        $this->assertSame([$f1], $f(['con_retenciones' => 'false']));
        $this->assertSame([$f2], $f(['con_pago' => 'true']));
        $this->assertSame([$f1], $f(['con_pago' => 'false']));
        $this->assertSame([$f2], $f(['enviada' => 'true']));
        $this->assertSame([$f2], $f(['con_tdc' => 'true']));
        $this->assertSame([$f1], $f(['origen' => 'servicio']));
        $this->assertSame([$f2], $f(['origen' => 'manual']));

        // search incluye razón social del cliente
        $this->assertSame([$f2], $f(['search' => 'globex']));
        $this->assertSame([$f1], $f(['search' => 'Enero']));

        // Valores inválidos no filtran
        $this->assertCount(2, $f(['tipo' => 'X']));
        $this->assertCount(2, $f(['importe_min' => 'mucho']));
        $this->assertCount(2, $f(['fecha_desde' => '2026-13-45']));
        $this->assertCount(2, $f(['cobrado' => 'quizas']));
    }

    // ------------------------------------------------------------
    // Servicios
    // ------------------------------------------------------------

    public function testServiciosOrdenDefaultInicioMasRecientePrimero(): void
    {
        $c = $this->crearCliente();
        $viejo = $this->crearServicio($c, $this->userId, ['fecha_inicio' => '2024-05-01']);
        $nuevo = $this->crearServicio($c, $this->userId, ['fecha_inicio' => '2026-02-01']);
        $medio = $this->crearServicio($c, $this->userId, ['fecha_inicio' => '2025-08-01']);

        $repo = new ServicioRepository();
        $this->assertSame([$nuevo, $medio, $viejo], $this->ids($repo->paginate([], 1, 25)->items()));
    }

    public function testServiciosFiltrosPorCampo(): void
    {
        $acme = $this->crearCliente(['razon_social' => 'Acme SRL']);
        $globex = $this->crearCliente(['razon_social' => 'Globex Corp']);
        $otroUser = $this->crearUsuario();

        $mant = $this->crearServicio($acme, $this->userId, [
            'tipo' => 'mantenimiento',
            'nombre' => 'Soporte mensual',
            'descripcion' => 'Mesa de ayuda',
            'moneda' => 'ARS',
            'importe_base' => 50000,
            'iva_porcentaje' => 21,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => null,
            'modo_facturacion' => 'mes_calendario',
            'frecuencia_ajuste_meses' => 6,
            'estado' => 'activo',
            'created_at' => '2025-12-20 10:00:00',
        ]);
        $this->crearCuota($mant, 1, '2026-02-01');

        $proy = $this->crearServicio($globex, $otroUser, [
            'tipo' => 'proyecto',
            'nombre' => 'Migracion ERP',
            'moneda' => 'USD',
            'importe_base' => 12000,
            'iva_porcentaje' => 0,
            'tipo_factura_default' => 'E',
            'fecha_inicio' => '2025-06-01',
            'fecha_fin' => '2025-12-31',
            'modo_facturacion' => null,
            'frecuencia_ajuste_meses' => null,
            'estado' => 'completado',
            'observaciones' => 'Cerrado en fecha',
            'created_at' => '2025-05-10 10:00:00',
        ]);
        $this->crearCuota($proy, 1, '2025-06-01', ['estado' => 'facturada']);

        $repo = new ServicioRepository();
        $f = fn (array $filters): array => $this->ids($repo->paginate($filters, 1, 25)->items());

        $this->assertSame([$mant], $f(['cliente_id' => (string) $acme]));
        $this->assertSame([$proy], $f(['tipo' => 'proyecto']));
        $this->assertSame([$proy], $f(['estado' => 'completado']));
        $this->assertSame([$proy], $f(['moneda' => 'USD']));
        $this->assertSame([$mant], $f(['modo_facturacion' => 'mes_calendario']));
        $this->assertSame([$proy], $f(['tipo_factura_default' => 'E']));
        $this->assertSame([$proy], $f(['created_by' => (string) $otroUser]));
        $this->assertSame([$mant], $f(['frecuencia_ajuste_meses' => '6']));
        // IVA 0 es un valor válido (no se tiene que tratar como "vacío")
        $this->assertSame([$proy], $f(['iva_porcentaje' => '0']));
        $this->assertSame([$mant], $f(['iva_porcentaje' => '21']));

        $this->assertSame([$mant], $f(['nombre' => 'soporte']));
        $this->assertSame([$mant], $f(['descripcion' => 'mesa']));
        $this->assertSame([$proy], $f(['observaciones' => 'cerrado']));

        $this->assertSame([$mant], $f(['inicio_desde' => '2025-12-01']));
        $this->assertSame([$proy], $f(['inicio_hasta' => '2025-12-01']));
        $this->assertSame([$proy], $f(['fin_hasta' => '2025-12-31']));
        $this->assertSame([$mant], $f(['creado_desde' => '2025-12-01']));

        $this->assertSame([$mant], $f(['importe_min' => '20000']));
        $this->assertSame([$proy], $f(['importe_max' => '20000']));

        $this->assertSame([$mant], $f(['indefinido' => 'true']));
        $this->assertSame([$proy], $f(['indefinido' => 'false']));
        $this->assertSame([$mant], $f(['con_ajuste' => 'true']));
        $this->assertSame([$proy], $f(['con_ajuste' => 'false']));
        $this->assertSame([$mant], $f(['con_cuotas_pendientes' => 'true']));
        $this->assertSame([$proy], $f(['con_cuotas_pendientes' => 'false']));

        // search incluye razón social del cliente
        $this->assertSame([$proy], $f(['search' => 'globex']));
        $this->assertSame([$mant], $f(['search' => 'ayuda']));

        $this->assertCount(2, $f(['estado' => 'inventado']));
        $this->assertCount(2, $f(['inicio_desde' => 'hoy']));
    }
}
