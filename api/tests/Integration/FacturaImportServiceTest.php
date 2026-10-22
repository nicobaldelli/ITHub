<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Services\FacturaImportService;
use ITHub\Api\Services\FacturaService;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Import de facturas históricas de punta a punta: preview + confirm sobre
 * CSV y sobre la plantilla xlsx que genera el propio servicio.
 */
final class FacturaImportServiceTest extends DatabaseTestCase
{
    private FacturaImportService $import;
    private User $user;
    private string $cuit;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ithub-import-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0750, true);
        $this->import = new FacturaImportService(
            $this->container->get(FacturaService::class),
            $this->container->get(AuditoriaService::class),
            $this->dir,
        );
        $this->user = User::find($this->crearUsuario(['rol' => 'ventas']));
        $this->cuit = self::cuitValido(1);
        $this->crearCliente(['cuit' => $this->cuit, 'razon_social' => 'Acme SA']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function csv(array $filas, string $delim = ','): string
    {
        $cab = ['Número', 'Tipo', 'CUIT', 'Fecha factura', 'Vencimiento', 'Moneda', 'Sin IVA', 'Con IVA', 'Total ARS', 'TDC', 'Total cobrado', 'Fecha pago', 'Cobrada'];
        $lineas = [implode($delim, $cab)];
        foreach ($filas as $f) {
            $lineas[] = implode($delim, $f);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lineas) . "\r\n";
    }

    private function filaOk(string $numero = '0001-00000001', array $override = []): array
    {
        return array_replace([
            $numero, 'A', $this->cuit, '2025-03-05', '2025-04-04', 'ARS', '100000', '121000', '', '', '', '', 'No',
        ], $override);
    }

    public function testPreviewDeUnCsvValidoResuelveClienteEstadoYTotales(): void
    {
        $p = $this->import->preview($this->csv([
            $this->filaOk('0001-00000001'),
            // cobrada por columna, con fecha de pago
            $this->filaOk('0001-00000002', [11 => '2025-03-28', 12 => 'Sí']),
            // vencida: vencimiento en el pasado, no cobrada
            $this->filaOk('0001-00000003', [4 => '2020-01-31']),
            // USD con TDC, vencimiento futuro
            // (el CSV es con coma: el decimal va con punto; el caso "1.234,56" lo cubre el unitario)
            $this->filaOk('E-0001-00000004', [1 => 'E', 4 => '2099-01-01', 5 => 'USD', 6 => '500', 7 => '500', 9 => '1180.50']),
        ]), 'historico.csv', $this->user);

        self::assertSame(['total' => 4, 'validas' => 4, 'con_error' => 0], $p['resumen']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $p['token']);
        self::assertSame([], $p['columnas_ignoradas']);

        [$f1, $f2, $f3, $f4] = $p['filas'];
        self::assertSame(2, $f1['n'], 'la fila 1 es la cabecera');
        self::assertSame('Acme SA', $f1['cliente']);
        self::assertSame('vencida', $f1['estado_previsto'], 'vencimiento 2025 ya pasó');
        self::assertFalse($f1['cobrada']);
        self::assertSame('2025-03-05', $f1['datos']['fecha_envio'], 'fecha_envio = fecha_factura si falta');

        self::assertTrue($f2['cobrada']);
        self::assertSame('cobrada', $f2['estado_previsto']);

        self::assertSame('vencida', $f3['estado_previsto']);

        self::assertSame('emitida', $f4['estado_previsto']);
        self::assertSame('USD', $f4['datos']['moneda']);
        self::assertSame(1180.5, $f4['datos']['tdc']);
        self::assertSame($this->cuit, $f4['datos']['cuit']);
    }

    public function testPreviewMarcaErroresPorFilaSinFrenarElResto(): void
    {
        $this->crearCliente(['cuit' => self::cuitValido(2), 'activo' => 0, 'razon_social' => 'Inactivo SRL']);
        Capsule::table('facturas_venta')->insert([
            'numero_factura' => 'YA-EXISTE', 'cliente_id' => 1, 'tipo' => 'A', 'cuit' => $this->cuit,
            'fecha_factura' => '2024-01-01', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        $p = $this->import->preview($this->csv([
            $this->filaOk('OK-1'),
            $this->filaOk('SIN-CLIENTE', [2 => '20-12345678-6']),
            $this->filaOk('CUIT-MAL', [2 => '20-12345678-9']),
            $this->filaOk('INACTIVO', [2 => self::cuitValido(2)]),
            $this->filaOk('YA-EXISTE'),
            $this->filaOk('REPETIDA'),
            $this->filaOk('REPETIDA'),
            $this->filaOk('FECHA-MAL', [3 => '2025-02-30']),
            $this->filaOk('USD-SIN-TDC', [5 => 'USD']),
            $this->filaOk('SIN-IMPORTE', [6 => '', 7 => 'abc']),
            $this->filaOk('TIPO-MAL', [1 => 'Z']),
            $this->filaOk('', []),
        ], ';'), 'con-errores.csv', $this->user);

        self::assertSame(12, $p['resumen']['total']);
        self::assertSame(2, $p['resumen']['validas'], 'OK-1 y la primera REPETIDA');
        self::assertSame(10, $p['resumen']['con_error']);

        $porNumero = [];
        foreach ($p['filas'] as $f) {
            $porNumero[$f['datos']['numero_factura']][] = $f['errores'];
        }
        self::assertSame([], $porNumero['OK-1'][0]);
        self::assertStringContainsString('No hay ningún cliente', $porNumero['SIN-CLIENTE'][0]['cuit']);
        self::assertArrayNotHasKey('cliente_id', $porNumero['SIN-CLIENTE'][0], 'no se duplica el error del CUIT');
        self::assertSame('CUIT inválido', $porNumero['CUIT-MAL'][0]['cuit']);
        self::assertStringContainsString('inactivo', $porNumero['INACTIVO'][0]['cuit']);
        self::assertSame('Ya existe una factura con ese número', $porNumero['YA-EXISTE'][0]['numero_factura']);
        self::assertSame([], $porNumero['REPETIDA'][0]);
        self::assertStringContainsString('Repetido en el archivo', $porNumero['REPETIDA'][1]['numero_factura']);
        self::assertArrayHasKey('fecha_factura', $porNumero['FECHA-MAL'][0]);
        self::assertArrayHasKey('tdc', $porNumero['USD-SIN-TDC'][0]);
        self::assertSame('Requerido', $porNumero['SIN-IMPORTE'][0]['importe_sin_iva']);
        self::assertSame('Debe ser un número', $porNumero['SIN-IMPORTE'][0]['importe_con_iva']);
        self::assertArrayHasKey('tipo', $porNumero['TIPO-MAL'][0]);
        self::assertArrayHasKey('numero_factura', $porNumero[''][0]);
    }

    public function testPreviewRechazaArchivosSinLasColumnasObligatorias(): void
    {
        try {
            $this->import->preview("Numero,Fecha\n1,2025-01-01\n", 'x.csv', $this->user);
            self::fail('debería fallar');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Faltan columnas obligatorias', $e->getMessage());
            self::assertContains('cuit', $e->getDetails()['columnas']);
        }

        $this->expectException(ValidationException::class);
        $this->import->preview($this->csv([]), 'vacio.csv', $this->user);
    }

    public function testPreviewIgnoraColumnasDelExportYSaltaFilasVacias(): void
    {
        $csv = "Número,Tipo,Cliente,CUIT,Fecha factura,Estado,Sin IVA,Con IVA,Columna rara\n"
            . "N-1,A,Acme SA,{$this->cuit},2025-01-10,emitida,100,121,zzz\n"
            . ",,,,,,,,\n"
            . "N-2,B,Acme SA,{$this->cuit},10/01/2025,cobrada,200,242,\n";

        $p = $this->import->preview($csv, 'export.csv', $this->user);

        self::assertSame(2, $p['resumen']['total'], 'la fila vacía se saltea');
        self::assertSame(['Columna rara'], $p['columnas_ignoradas'], 'Cliente y Estado se ignoran en silencio');
        self::assertSame('2025-01-10', $p['filas'][1]['datos']['fecha_factura']);
    }

    public function testConfirmCreaLasFacturasConCobranzaResueltaYAudita(): void
    {
        $p = $this->import->preview($this->csv([
            $this->filaOk('H-1', [4 => '2099-12-31']),
            $this->filaOk('H-2', [10 => '121000', 11 => '2025-03-28', 12 => 'Sí']),
            $this->filaOk('H-3', [1 => 'E', 4 => '', 5 => 'USD', 6 => '500', 7 => '500', 9 => '1000', 12 => 'x']),
        ]), 'ok.csv', $this->user);

        $r = $this->import->confirm($p['token'], false, $this->user, $this->makeRequest());

        self::assertSame(3, $r['importadas']);
        self::assertSame(0, $r['omitidas']);
        self::assertCount(3, $r['ids']);

        $h1 = FacturaVenta::where('numero_factura', 'H-1')->first();
        self::assertSame('emitida', $h1->estado);
        self::assertFalse($h1->check_cobranza);
        self::assertNull($h1->servicio_cuota_id, 'histórica: sin cuota');
        self::assertSame('121000.00', $h1->importe_total_pesos, 'ARS: total = con IVA');
        self::assertSame($this->user->id, $h1->created_by);
        self::assertSame('2025-03-05', $h1->fecha_envio->format('Y-m-d'));

        $h2 = FacturaVenta::where('numero_factura', 'H-2')->first();
        self::assertSame('cobrada', $h2->estado);
        self::assertTrue($h2->check_cobranza);
        self::assertSame($this->user->id, $h2->check_cobranza_user_id);
        self::assertNotNull($h2->check_cobranza_fecha);
        self::assertSame('2025-03-28', $h2->fecha_pago->format('Y-m-d'));
        self::assertSame('121000.00', $h2->total_cobrado);

        $h3 = FacturaVenta::where('numero_factura', 'H-3')->first();
        self::assertSame('cobrada', $h3->estado);
        self::assertSame('500000.00', $h3->importe_total_pesos, 'USD: con IVA x TDC');
        self::assertSame('500000.00', $h3->total_cobrado, 'cobrada sin total_cobrado: se asume el total');
        self::assertSame('2025-03-05', $h3->fecha_pago->format('Y-m-d'), 'cobrada sin fecha_pago: fecha de factura');

        // Auditoría: una por factura (con origen) + una del import
        self::assertSame(3, Capsule::table('auditoria')->where('entidad', 'factura')->where('accion', 'crear')->count());
        $aud = Capsule::table('auditoria')->where('accion', 'crear')->first();
        self::assertStringContainsString('import_historico', (string) $aud->campos_modificados);
        $imp = Capsule::table('auditoria')->where('accion', 'import')->first();
        self::assertNotNull($imp);
        self::assertStringContainsString('"importadas":3', (string) $imp->campos_modificados);

        // El token se consumió
        $this->expectException(ValidationException::class);
        $this->import->confirm($p['token'], false, $this->user, $this->makeRequest());
    }

    public function testConfirmEsTodoONadaSalvoQueSePidaOmitir(): void
    {
        $p = $this->import->preview($this->csv([
            $this->filaOk('OK-1'),
            $this->filaOk('MAL', [2 => '20-12345678-9']),
        ]), 'mixto.csv', $this->user);

        try {
            $this->import->confirm($p['token'], false, $this->user, $this->makeRequest());
            self::fail('con errores y sin omitir no importa nada');
        } catch (ValidationException $e) {
            self::assertSame([3], $e->getDetails()['filas_con_error']);
        }
        self::assertSame(0, FacturaVenta::count());

        $r = $this->import->confirm($p['token'], true, $this->user, $this->makeRequest());
        self::assertSame(['importadas' => 1, 'omitidas' => 1], ['importadas' => $r['importadas'], 'omitidas' => $r['omitidas']]);
        self::assertSame(1, FacturaVenta::count());
    }

    public function testConfirmAbortaTodoSiAlgoCambioDesdeLaPreview(): void
    {
        $p = $this->import->preview($this->csv([
            $this->filaOk('A-1'),
            $this->filaOk('A-2'),
        ]), 'x.csv', $this->user);

        // Entre preview y confirm alguien carga A-2 a mano
        Capsule::table('facturas_venta')->insert([
            'numero_factura' => 'A-2', 'cliente_id' => 1, 'tipo' => 'A', 'cuit' => $this->cuit,
            'fecha_factura' => '2024-01-01', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        try {
            $this->import->confirm($p['token'], false, $this->user, $this->makeRequest());
            self::fail('debería abortar');
        } catch (ValidationException $e) {
            self::assertStringStartsWith('Fila 3:', $e->getMessage());
            self::assertSame(3, $e->getDetails()['fila']);
        }
        self::assertSame(1, FacturaVenta::count(), 'A-1 se revirtió: solo queda la manual');
    }

    public function testElTokenEsDelUsuarioYVence(): void
    {
        $p = $this->import->preview($this->csv([$this->filaOk()]), 'x.csv', $this->user);
        $otro = User::find($this->crearUsuario(['rol' => 'admin']));

        try {
            $this->import->confirm($p['token'], false, $otro, $this->makeRequest());
            self::fail('otro usuario no puede confirmar');
        } catch (ValidationException $e) {
            self::assertSame('ajeno', $e->getDetails()['token']);
        }

        try {
            $this->import->confirm('no-es-hex', false, $this->user, $this->makeRequest());
            self::fail('token con formato inválido');
        } catch (ValidationException $e) {
            self::assertSame('inválido', $e->getDetails()['token']);
        }

        // Vencido: se edita la fecha de creación guardada
        $ruta = $this->dir . "/preview_{$p['token']}.json";
        $json = json_decode((string) file_get_contents($ruta), true);
        $json['creado'] = time() - 7200;
        file_put_contents($ruta, json_encode($json));
        try {
            $this->import->confirm($p['token'], false, $this->user, $this->makeRequest());
            self::fail('token vencido');
        } catch (ValidationException $e) {
            self::assertSame('vencido', $e->getDetails()['token']);
        }
        self::assertFileDoesNotExist($ruta, 'el vencido se borra');
    }

    public function testLaPlantillaGeneradaSeReimportaTalCual(): void
    {
        // La plantilla trae dos filas de ejemplo con CUIT 30-70826853-0
        $this->crearCliente(['cuit' => '30-70826853-0', 'razon_social' => 'Cliente Plantilla']);

        $out = $this->import->plantilla();
        self::assertSame('ithub-plantilla-import-facturas.xlsx', $out['filename']);
        self::assertStringStartsWith("PK\x03\x04", $out['content'], 'es un xlsx (zip)');

        $p = $this->import->preview($out['content'], $out['filename'], $this->user);

        self::assertSame(2, $p['resumen']['total']);
        self::assertSame(2, $p['resumen']['validas'], json_encode(array_column($p['filas'], 'errores')));
        self::assertSame('Cliente Plantilla', $p['filas'][0]['cliente']);
        self::assertSame('cobrada', $p['filas'][0]['estado_previsto']);
        self::assertSame('USD', $p['filas'][1]['datos']['moneda']);
        self::assertSame(1180.5, $p['filas'][1]['datos']['tdc']);
        self::assertSame('2025-06-10', $p['filas'][1]['datos']['fecha_factura']);

        $r = $this->import->confirm($p['token'], false, $this->user, $this->makeRequest());
        self::assertSame(2, $r['importadas']);
    }
}
