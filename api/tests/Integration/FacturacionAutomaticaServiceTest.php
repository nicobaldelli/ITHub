<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\ServicioCuota;
use ITHub\Api\Services\FacturacionAutomaticaService;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Cron de facturación automática: genera una factura AUTO-x por cada cuota
 * pendiente cuya fecha llegó, y NUNCA dos para la misma cuota.
 */
final class FacturacionAutomaticaServiceTest extends DatabaseTestCase
{
    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    private FacturacionAutomaticaService $cron;
    private int $userId;
    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cron = $this->container->get(FacturacionAutomaticaService::class);
        $this->userId = $this->crearUsuario();
        $this->clienteId = $this->crearCliente(['plazo_pago_default' => 30, 'banco' => 'Banco Nación']);
    }

    public function testFacturaLasCuotasVencidasYDejaLasFuturasPendientes(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId, ['fecha_inicio' => $this->fecha(-1)]);
        $ayer = $this->crearCuota($servicioId, 1, $this->fecha(-1), ['importe' => 1000]);
        $hoy = $this->crearCuota($servicioId, 2, $this->fecha(0), ['importe' => 1000]);
        $futura = $this->crearCuota($servicioId, 3, $this->fecha(30), ['importe' => 1000]);

        $resumen = $this->cron->procesar();

        self::assertSame(2, $resumen['cuotas_procesadas']);
        self::assertSame(2, $resumen['facturas_creadas']);
        self::assertSame(0, $resumen['errores']);
        self::assertSame(2, FacturaVenta::count());

        $factura = FacturaVenta::where('servicio_cuota_id', $ayer)->first();
        self::assertNotNull($factura);
        self::assertSame("AUTO-{$ayer}", $factura->numero_factura, 'número placeholder hasta que se marque enviada');
        self::assertSame('emitida', $factura->estado);
        self::assertNull($factura->fecha_envio, 'queda pendiente de envío manual');
        self::assertSame('A', $factura->tipo);
        self::assertSame('ARS', $factura->moneda);
        self::assertSame('1000.00', $factura->importe_sin_iva);
        self::assertSame('1210.00', $factura->importe_con_iva, 'IVA 21%');
        self::assertSame('1210.00', $factura->importe_total_pesos);
        self::assertNull($factura->tdc);
        self::assertSame($this->fecha(0), $factura->fecha_factura->format('Y-m-d'));
        self::assertSame($this->fecha(30), $factura->vencimiento->format('Y-m-d'), 'hoy + plazo del cliente');
        self::assertSame(30, $factura->plazo_pago);
        self::assertSame('Banco Nación', $factura->banco, 'snapshot de datos del cliente');
        self::assertSame('Cuota 1', $factura->mes_cubierto);
        self::assertNull($factura->created_by, 'generada por el cron, sin usuario');

        // Cuotas: las facturadas quedan enlazadas, la futura intacta
        self::assertSame('facturada', ServicioCuota::find($ayer)->estado);
        self::assertSame($factura->id, ServicioCuota::find($ayer)->factura_id);
        self::assertSame('facturada', ServicioCuota::find($hoy)->estado);
        self::assertSame('pendiente', ServicioCuota::find($futura)->estado);
        self::assertNull(ServicioCuota::find($futura)->factura_id);

        // Auditoría de sistema (sin user_id) por cada factura
        $aud = Capsule::table('auditoria')->where('entidad', 'factura')->where('accion', 'crear')->get();
        self::assertCount(2, $aud);
        self::assertNull($aud[0]->user_id);
        self::assertStringContainsString('cron_facturacion_automatica', (string) $aud[0]->campos_modificados);
    }

    public function testEsIdempotenteCorrerloDosVecesNoDuplicaFacturas(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId);
        $this->crearCuota($servicioId, 1, $this->fecha(-1));

        $primera = $this->cron->procesar();
        $segunda = $this->cron->procesar();

        self::assertSame(1, $primera['facturas_creadas']);
        self::assertSame(0, $segunda['cuotas_procesadas'], 'la cuota ya no está pendiente');
        self::assertSame(0, $segunda['facturas_creadas']);
        self::assertSame(1, FacturaVenta::count());
    }

    public function testNoDuplicaSiLaCuotaYaTieneUnaFacturaActivaCargadaAMano(): void
    {
        // Raza con el flujo manual: la cuota sigue "pendiente" pero ya existe
        // una factura activa apuntándole.
        $servicioId = $this->crearServicio($this->clienteId, $this->userId);
        $cuotaId = $this->crearCuota($servicioId, 1, $this->fecha(-1));
        Capsule::table('facturas_venta')->insert([
            'numero_factura' => '0001-00000001',
            'cliente_id' => $this->clienteId,
            'tipo' => 'A',
            'cuit' => self::cuitValido(1),
            'fecha_factura' => $this->fecha(-1),
            'servicio_cuota_id' => $cuotaId,
            'created_by' => $this->userId,
            'updated_by' => $this->userId,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        $resumen = $this->cron->procesar();

        self::assertSame(1, $resumen['cuotas_procesadas']);
        self::assertSame(0, $resumen['facturas_creadas']);
        self::assertSame(0, $resumen['errores'], 'se saltea en silencio, no es un error');
        self::assertSame(1, FacturaVenta::count());
    }

    public function testIgnoraServiciosNoActivosYClientesInactivos(): void
    {
        $pausado = $this->crearServicio($this->clienteId, $this->userId, ['estado' => 'pausado']);
        $this->crearCuota($pausado, 1, $this->fecha(-1));

        $cancelado = $this->crearServicio($this->clienteId, $this->userId, ['estado' => 'cancelado']);
        $this->crearCuota($cancelado, 1, $this->fecha(-1));

        $borrado = $this->crearServicio($this->clienteId, $this->userId, ['deleted_at' => $this->now()]);
        $this->crearCuota($borrado, 1, $this->fecha(-1));

        $clienteInactivo = $this->crearCliente(['activo' => 0]);
        $deInactivo = $this->crearServicio($clienteInactivo, $this->userId);
        $cuotaInactivo = $this->crearCuota($deInactivo, 1, $this->fecha(-1));

        $resumen = $this->cron->procesar();

        // Los servicios no activos ni entran en la consulta; el cliente inactivo
        // entra pero se saltea sin error y la cuota sigue pendiente.
        self::assertSame(1, $resumen['cuotas_procesadas']);
        self::assertSame(0, $resumen['facturas_creadas']);
        self::assertSame(0, $resumen['errores']);
        self::assertSame(0, FacturaVenta::count());
        self::assertSame('pendiente', ServicioCuota::find($cuotaInactivo)->estado);
    }

    public function testCuotasOmitidasOCanceladasNoSeFacturan(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId);
        $this->crearCuota($servicioId, 1, $this->fecha(-5), ['estado' => 'omitida']);
        $this->crearCuota($servicioId, 2, $this->fecha(-5), ['estado' => 'cancelada']);
        $this->crearCuota($servicioId, 3, $this->fecha(-5), ['estado' => 'facturada']);

        $resumen = $this->cron->procesar();

        self::assertSame(0, $resumen['cuotas_procesadas']);
        self::assertSame(0, FacturaVenta::count());
    }

    public function testServicioEnDolaresDejaTdcYTotalEnPesosParaCargarAMano(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId, ['moneda' => 'USD', 'iva_porcentaje' => 10.5]);
        $cuotaId = $this->crearCuota($servicioId, 1, $this->fecha(0), ['importe' => 200]);

        $this->cron->procesar();

        $factura = FacturaVenta::where('servicio_cuota_id', $cuotaId)->first();
        self::assertSame('USD', $factura->moneda);
        self::assertSame('200.00', $factura->importe_sin_iva);
        self::assertSame('221.00', $factura->importe_con_iva, 'IVA 10.5%');
        self::assertNull($factura->tdc, 'el admin carga el TDC al marcar enviada');
        self::assertNull($factura->importe_total_pesos);
    }

    public function testRespetaElTipoDeFacturaDefaultDelServicioYElPlazoDelCliente(): void
    {
        $clienteSinPlazo = $this->crearCliente(['plazo_pago_default' => null]);
        $servicioId = $this->crearServicio($clienteSinPlazo, $this->userId, ['tipo_factura_default' => 'B']);
        $cuotaId = $this->crearCuota($servicioId, 1, $this->fecha(0));

        $this->cron->procesar();

        $factura = FacturaVenta::where('servicio_cuota_id', $cuotaId)->first();
        self::assertSame('B', $factura->tipo);
        self::assertNull($factura->vencimiento, 'sin plazo no hay vencimiento calculado');
        self::assertNull($factura->plazo_pago);
    }

    public function testSinTemplateElDetalleEsNombreDelServicioYEtiqueta(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId, [
            'nombre' => 'Soporte hosting',
            'template_factura' => null,
        ]);
        $cuotaId = $this->crearCuota($servicioId, 1, $this->fecha(0), ['etiqueta' => 'Septiembre 2026']);

        $this->cron->procesar();

        self::assertSame(
            'Soporte hosting — Septiembre 2026',
            FacturaVenta::where('servicio_cuota_id', $cuotaId)->first()->detalle_factura,
        );
    }

    public function testRenderizaLosPlaceholdersDelTemplate(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId, [
            'fecha_inicio' => $this->fecha(-1),
            'frecuencia_ajuste_meses' => 12,
            'template_factura' => 'Mantenimiento {MES_NOMBRE} {ANIO} (mes {NUMERO_MES}) - cuota {POS_EN_CICLO} de {TOTAL_CICLO} - {INPUT:horas:10} hs - [{INPUT:sin_default}]',
        ]);
        $ayer = $this->crearCuota($servicioId, 1, $this->fecha(-1));
        $hoy = $this->crearCuota($servicioId, 2, $this->fecha(0));

        $this->cron->procesar();

        $fAyer = FacturaVenta::where('servicio_cuota_id', $ayer)->first();
        $fHoy = FacturaVenta::where('servicio_cuota_id', $hoy)->first();

        $tsAyer = strtotime($this->fecha(-1));
        $tsHoy = strtotime($this->fecha(0));

        self::assertSame(
            sprintf(
                'Mantenimiento %s %s (mes %d) - cuota 1 de 12 - 10 hs - []',
                self::MESES[(int) date('n', $tsAyer)],
                date('Y', $tsAyer),
                (int) date('n', $tsAyer),
            ),
            $fAyer->detalle_factura,
        );
        // Segunda cuota desde el inicio de la tarifa: posición 2
        self::assertSame(
            sprintf(
                'Mantenimiento %s %s (mes %d) - cuota 2 de 12 - 10 hs - []',
                self::MESES[(int) date('n', $tsHoy)],
                date('Y', $tsHoy),
                (int) date('n', $tsHoy),
            ),
            $fHoy->detalle_factura,
        );
        self::assertSame((int) date('n', $tsAyer), $fAyer->numero_mes);
    }

    public function testLaPosicionEnElCicloSeReiniciaConUnAjusteAplicado(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId, [
            'fecha_inicio' => $this->fecha(-60),
            'frecuencia_ajuste_meses' => 6,
            'template_factura' => '{POS_EN_CICLO}/{TOTAL_CICLO}',
        ]);
        // Dos cuotas viejas ya facturadas antes del ajuste
        $this->crearCuota($servicioId, 1, $this->fecha(-60), ['estado' => 'facturada']);
        $this->crearCuota($servicioId, 2, $this->fecha(-30), ['estado' => 'facturada']);
        // Ajuste de tarifa aplicado hace 10 días
        Capsule::table('servicio_ajustes')->insert([
            'servicio_id' => $servicioId,
            'tipo' => 'espontaneo',
            'fecha_aplicacion' => $this->fecha(-10),
            'importe_anterior' => 1000,
            'importe_nuevo' => 1300,
            'aplicado' => 1,
            'aplicado_at' => $this->now(),
            'created_by' => $this->userId,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        $cuotaId = $this->crearCuota($servicioId, 3, $this->fecha(0), ['importe' => 1300]);

        $this->cron->procesar();

        self::assertSame('1/6', FacturaVenta::where('servicio_cuota_id', $cuotaId)->first()->detalle_factura);
    }

    public function testUnErrorEnUnaCuotaNoFrenaLasDemas(): void
    {
        $servicioId = $this->crearServicio($this->clienteId, $this->userId);
        $ok = $this->crearCuota($servicioId, 1, $this->fecha(-2));
        // Esta cuota va a chocar con el unique de numero_factura: ya existe AUTO-<id>
        $rota = $this->crearCuota($servicioId, 2, $this->fecha(-1));
        Capsule::table('facturas_venta')->insert([
            'numero_factura' => "AUTO-{$rota}",
            'cliente_id' => $this->clienteId,
            'tipo' => 'A',
            'cuit' => self::cuitValido(1),
            'fecha_factura' => $this->fecha(-1),
            'created_by' => $this->userId,
            'updated_by' => $this->userId,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        $resumen = $this->cron->procesar();

        self::assertSame(2, $resumen['cuotas_procesadas']);
        self::assertSame(1, $resumen['facturas_creadas']);
        self::assertSame(1, $resumen['errores']);
        self::assertSame('facturada', ServicioCuota::find($ok)->estado);
        self::assertSame('pendiente', ServicioCuota::find($rota)->estado, 'la transacción de la cuota rota se revierte');
        self::assertCount(2, $resumen['detalles']);
    }
}
