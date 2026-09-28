<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Validators\FacturaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FacturaValidatorTest extends TestCase
{
    /** Payload mínimo válido para create. */
    private const BASE = [
        'numero_factura' => 'A-0001-00001234',
        'cliente_id' => 7,
        'tipo' => 'A',
        'cuit' => '20-12345678-6',
        'fecha_factura' => '2026-06-01',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string> details de la ValidationException
     */
    private static function detalles(array $data, bool $isUpdate = false): array
    {
        try {
            FacturaValidator::validate($data, $isUpdate);
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    // ------------------------------------------------------------
    // Create: requeridos
    // ------------------------------------------------------------

    public function testCreateValidoDevuelveElPayloadNormalizado(): void
    {
        $clean = FacturaValidator::validate(self::BASE);

        self::assertSame('A-0001-00001234', $clean['numero_factura']);
        self::assertSame(7, $clean['cliente_id']);
        self::assertSame('A', $clean['tipo']);
        self::assertSame('20-12345678-6', $clean['cuit']);
        self::assertSame('2026-06-01', $clean['fecha_factura']);
    }

    public function testCreateVacioExigeNumeroClienteTipoCuitYFecha(): void
    {
        $details = self::detalles([]);

        foreach (['numero_factura', 'cliente_id', 'tipo', 'cuit', 'fecha_factura'] as $campo) {
            self::assertArrayHasKey($campo, $details, "Falta error en {$campo}");
        }
    }

    public function testLanzaValidationExceptionCon422YCodigo(): void
    {
        try {
            FacturaValidator::validate([]);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
            self::assertSame('Datos inválidos', $e->getMessage());
            self::assertNotEmpty($e->getDetails());
        }
    }

    public function testNumeroFacturaVacioOMuyLargoEsRechazado(): void
    {
        self::assertArrayHasKey('numero_factura', self::detalles(['numero_factura' => '   '] + self::BASE));
        self::assertArrayHasKey('numero_factura', self::detalles(['numero_factura' => str_repeat('9', 51)] + self::BASE));
    }

    public function testClienteIdDebeSerNumericoPositivo(): void
    {
        self::assertArrayHasKey('cliente_id', self::detalles(['cliente_id' => 0] + self::BASE));
        self::assertArrayHasKey('cliente_id', self::detalles(['cliente_id' => -3] + self::BASE));
        self::assertArrayHasKey('cliente_id', self::detalles(['cliente_id' => 'abc'] + self::BASE));
    }

    public function testClienteIdNumericoComoStringSeCasteaAEntero(): void
    {
        $clean = FacturaValidator::validate(['cliente_id' => '12'] + self::BASE);
        self::assertSame(12, $clean['cliente_id']);
    }

    // ------------------------------------------------------------
    // CUIT
    // ------------------------------------------------------------

    public function testCuitConChecksumInvalidoEsRechazado(): void
    {
        $details = self::detalles(['cuit' => '20-12345678-9'] + self::BASE);
        self::assertSame('CUIT inválido', $details['cuit']);
    }

    public function testCuitSinGuionesSeNormalizaAlFormatoCanonico(): void
    {
        $clean = FacturaValidator::validate(['cuit' => '20123456786'] + self::BASE);
        self::assertSame('20-12345678-6', $clean['cuit']);
    }

    // ------------------------------------------------------------
    // Tipo
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function tiposValidos(): array
    {
        $out = [];
        foreach (FacturaVenta::TIPOS as $tipo) {
            $out[$tipo] = [$tipo];
        }
        return $out;
    }

    #[DataProvider('tiposValidos')]
    public function testAceptaTodosLosTiposDeFactura(string $tipo): void
    {
        $clean = FacturaValidator::validate(['tipo' => $tipo] + self::BASE);
        self::assertSame($tipo, $clean['tipo']);
    }

    public function testRechazaTipoDesconocidoOEnMinuscula(): void
    {
        self::assertArrayHasKey('tipo', self::detalles(['tipo' => 'C'] + self::BASE));
        self::assertArrayHasKey('tipo', self::detalles(['tipo' => 'a'] + self::BASE));
    }

    // ------------------------------------------------------------
    // Update parcial
    // ------------------------------------------------------------

    public function testUpdateAceptaPayloadParcialSinLosRequeridosDeCreate(): void
    {
        $clean = FacturaValidator::validate(['observaciones' => 'Pagó en dos partes'], true);
        self::assertSame(['observaciones' => 'Pagó en dos partes'], $clean);
    }

    public function testUpdateVacioDevuelveArrayVacio(): void
    {
        self::assertSame([], FacturaValidator::validate([], true));
    }

    public function testUpdateValidaSoloLasClavesPresentes(): void
    {
        $details = self::detalles(['cuit' => '20-12345678-9'], true);
        self::assertSame(['cuit'], array_keys($details));

        $details = self::detalles(['numero_factura' => ''], true);
        self::assertSame(['numero_factura'], array_keys($details));

        $details = self::detalles(['cliente_id' => null], true);
        self::assertSame(['cliente_id'], array_keys($details));
    }

    public function testUpdateNoExigeFechaFactura(): void
    {
        $clean = FacturaValidator::validate(['fecha_pago' => '2026-06-15'], true);
        self::assertSame('2026-06-15', $clean['fecha_pago']);
    }

    // ------------------------------------------------------------
    // Moneda / TDC
    // ------------------------------------------------------------

    public function testMonedaArsEsValidaSinTdc(): void
    {
        $clean = FacturaValidator::validate(['moneda' => 'ARS'] + self::BASE);
        self::assertSame('ARS', $clean['moneda']);
        self::assertArrayNotHasKey('tdc', $clean);
    }

    public function testMonedaUsdRequiereTdcMayorACero(): void
    {
        $details = self::detalles(['moneda' => 'USD'] + self::BASE);
        self::assertSame('Requerido cuando moneda=USD', $details['tdc']);

        $details = self::detalles(['moneda' => 'USD', 'tdc' => 0] + self::BASE);
        self::assertArrayHasKey('tdc', $details);

        $details = self::detalles(['moneda' => 'USD', 'tdc' => ''] + self::BASE);
        self::assertArrayHasKey('tdc', $details);
    }

    public function testMonedaUsdConTdcValidoPasaYCasteaAFloat(): void
    {
        $clean = FacturaValidator::validate(['moneda' => 'USD', 'tdc' => '1250.75'] + self::BASE);
        self::assertSame('USD', $clean['moneda']);
        self::assertSame(1250.75, $clean['tdc']);
    }

    public function testMonedaUsdEnUpdateTambienExigeTdc(): void
    {
        $details = self::detalles(['moneda' => 'USD'], true);
        self::assertArrayHasKey('tdc', $details);
    }

    /** @return array<string, array{mixed}> */
    public static function monedasInvalidas(): array
    {
        return [
            'EUR' => ['EUR'],
            'minuscula' => ['ars'],
            'vacia' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('monedasInvalidas')]
    public function testRechazaMonedasFueraDeArsUsd(mixed $moneda): void
    {
        self::assertArrayHasKey('moneda', self::detalles(['moneda' => $moneda] + self::BASE));
    }

    public function testTdcNegativoONoNumericoEsRechazadoAunEnArs(): void
    {
        self::assertArrayHasKey('tdc', self::detalles(['tdc' => -1] + self::BASE));
        self::assertArrayHasKey('tdc', self::detalles(['tdc' => 0] + self::BASE));
        self::assertArrayHasKey('tdc', self::detalles(['tdc' => 'abc'] + self::BASE));
    }

    public function testTdcVacioONullSeNormalizaANull(): void
    {
        self::assertNull(FacturaValidator::validate(['tdc' => ''] + self::BASE)['tdc']);
        self::assertNull(FacturaValidator::validate(['tdc' => null] + self::BASE)['tdc']);
    }

    // ------------------------------------------------------------
    // Importes
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function camposImporte(): array
    {
        return [
            'importe_sin_iva' => ['importe_sin_iva'],
            'importe_con_iva' => ['importe_con_iva'],
            'importe_total_pesos' => ['importe_total_pesos'],
            'retenciones' => ['retenciones'],
            'total_cobrado' => ['total_cobrado'],
        ];
    }

    #[DataProvider('camposImporte')]
    public function testImportesNegativosSonRechazados(string $campo): void
    {
        $details = self::detalles([$campo => -0.01] + self::BASE);
        self::assertSame('Debe ser un número >= 0', $details[$campo]);
    }

    #[DataProvider('camposImporte')]
    public function testImportesNoNumericosSonRechazados(string $campo): void
    {
        self::assertArrayHasKey($campo, self::detalles([$campo => 'mil'] + self::BASE));
    }

    #[DataProvider('camposImporte')]
    public function testImporteCeroEsValidoYSeCasteaAFloat(string $campo): void
    {
        $clean = FacturaValidator::validate([$campo => '0'] + self::BASE);
        self::assertSame(0.0, $clean[$campo]);
    }

    public function testImportesVaciosSeNormalizanANull(): void
    {
        $clean = FacturaValidator::validate(['importe_sin_iva' => '', 'retenciones' => null] + self::BASE);
        self::assertNull($clean['importe_sin_iva']);
        self::assertNull($clean['retenciones']);
    }

    public function testTotalCobradoNoPuedeSuperarElImporteTotal(): void
    {
        $details = self::detalles(['importe_total_pesos' => 1000, 'total_cobrado' => 1000.02] + self::BASE);
        self::assertSame('No puede superar el importe total', $details['total_cobrado']);
    }

    public function testTotalCobradoIgualAlTotalOConDiferenciaDeUnCentavoEsValido(): void
    {
        $clean = FacturaValidator::validate(['importe_total_pesos' => 1000, 'total_cobrado' => 1000] + self::BASE);
        self::assertSame(1000.0, $clean['total_cobrado']);

        $clean = FacturaValidator::validate(['importe_total_pesos' => 1000, 'total_cobrado' => 1000.005] + self::BASE);
        self::assertSame(1000.005, $clean['total_cobrado']);
    }

    // ------------------------------------------------------------
    // Fechas
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function camposFecha(): array
    {
        return [
            'fecha_factura' => ['fecha_factura'],
            'fecha_envio' => ['fecha_envio'],
            'vencimiento' => ['vencimiento'],
            'fecha_pago' => ['fecha_pago'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function fechasInvalidas(): array
    {
        return [
            'formato argentino' => ['01/06/2026'],
            'con barras' => ['2026/06/01'],
            'sin separadores' => ['20260601'],
            'texto' => ['hoy'],
            'con hora' => ['2026-06-01 10:00:00'],
            'anio menor a 1900' => ['1899-12-31'],
            'anio mayor a 2100' => ['2101-01-01'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function testRechazaFechaFacturaConFormatoInvalido(string $fecha): void
    {
        $details = self::detalles(['fecha_factura' => $fecha] + self::BASE);
        self::assertSame('Fecha inválida (formato YYYY-MM-DD)', $details['fecha_factura']);
    }

    #[DataProvider('camposFecha')]
    public function testValidaTodasLasFechasConFormatoIso(string $campo): void
    {
        self::assertArrayHasKey($campo, self::detalles([$campo => '31-12-2026'] + self::BASE));

        $clean = FacturaValidator::validate([$campo => '2026-12-31'] + self::BASE);
        self::assertSame('2026-12-31', $clean[$campo]);
    }

    public function testFechasOpcionalesVaciasSeNormalizanANull(): void
    {
        $clean = FacturaValidator::validate(['fecha_envio' => '', 'fecha_pago' => null] + self::BASE);
        self::assertNull($clean['fecha_envio']);
        self::assertNull($clean['fecha_pago']);
    }

    /** @return array<string, array{string}> */
    public static function fechasQueDesbordan(): array
    {
        return [
            '30 de febrero' => ['2026-02-30'],
            '31 de junio' => ['2026-06-31'],
            'mes 13' => ['2026-13-01'],
        ];
    }

    #[DataProvider('fechasQueDesbordan')]
    public function testFechaConDiaOMesInexistenteSeRechaza(string $fecha): void
    {
        // DateTimeImmutable::createFromFormat('Y-m-d', '2026-02-30') NO devuelve false:
        // desborda a 2026-03-02 (y '2026-13-01' a 2027-01-01). El validador tiene que
        // detectarlo comparando la fecha reconstruida con la original.
        try {
            FacturaValidator::validate(['fecha_factura' => $fecha] + self::BASE);
            self::fail("Debería rechazar la fecha inexistente {$fecha}");
        } catch (ValidationException $e) {
            self::assertArrayHasKey('fecha_factura', $e->getDetails());
        }
    }

    // ------------------------------------------------------------
    // Otros campos
    // ------------------------------------------------------------

    public function testNumeroMesDebeEstarEntre1Y12(): void
    {
        self::assertArrayHasKey('numero_mes', self::detalles(['numero_mes' => 0] + self::BASE));
        self::assertArrayHasKey('numero_mes', self::detalles(['numero_mes' => 13] + self::BASE));

        $clean = FacturaValidator::validate(['numero_mes' => '6'] + self::BASE);
        self::assertSame(6, $clean['numero_mes']);
    }

    public function testPlazoPagoDebeEstarEntre0Y3650(): void
    {
        self::assertArrayHasKey('plazo_pago', self::detalles(['plazo_pago' => -1] + self::BASE));
        self::assertArrayHasKey('plazo_pago', self::detalles(['plazo_pago' => 3651] + self::BASE));

        $clean = FacturaValidator::validate(['plazo_pago' => '30'] + self::BASE);
        self::assertSame(30, $clean['plazo_pago']);
    }

    public function testCbuDebeTener22Digitos(): void
    {
        self::assertSame('22 dígitos requeridos', self::detalles(['cbu' => '123'] + self::BASE)['cbu']);
        self::assertArrayHasKey('cbu', self::detalles(['cbu' => str_repeat('1', 23)] + self::BASE));
    }

    public function testCbuConSeparadoresSeNormalizaASoloDigitos(): void
    {
        $clean = FacturaValidator::validate(['cbu' => '0170-0992-2000-0067-7973-70'] + self::BASE);
        self::assertSame('0170099220000067797370', $clean['cbu']);
    }

    public function testCbuVacioSeNormalizaANull(): void
    {
        self::assertNull(FacturaValidator::validate(['cbu' => ''] + self::BASE)['cbu']);
    }

    public function testEmailsInvalidosSonRechazados(): void
    {
        $details = self::detalles([
            'mail_envio_factura' => 'no-es-mail',
            'mail_gestion_cobranza' => 'tampoco@',
        ] + self::BASE);

        self::assertSame('Email inválido', $details['mail_envio_factura']);
        self::assertSame('Email inválido', $details['mail_gestion_cobranza']);
    }

    public function testEmailsValidosPasan(): void
    {
        $clean = FacturaValidator::validate(['mail_envio_factura' => 'facturas@cliente.com.ar'] + self::BASE);
        self::assertSame('facturas@cliente.com.ar', $clean['mail_envio_factura']);
    }

    public function testEstadoDebeSerUnoDeLosPermitidos(): void
    {
        self::assertArrayHasKey('estado', self::detalles(['estado' => 'pagada'] + self::BASE));

        foreach (FacturaVenta::ESTADOS as $estado) {
            $clean = FacturaValidator::validate(['estado' => $estado] + self::BASE);
            self::assertSame($estado, $clean['estado']);
        }
    }

    /** @return array<string, array{string}> */
    public static function contenidoPeligroso(): array
    {
        return [
            'script' => ['<script>alert(1)</script>'],
            'script mayusculas' => ['<SCRIPT src=x>'],
            'iframe con espacios' => ['< iframe src="x">'],
            'object' => ['<object data="x">'],
            'embed' => ['<embed src="x">'],
        ];
    }

    #[DataProvider('contenidoPeligroso')]
    public function testRechazaScriptsEnObservacionesYDetalle(string $contenido): void
    {
        $details = self::detalles(['observaciones' => $contenido, 'detalle_factura' => $contenido] + self::BASE);
        self::assertSame('Contenido no permitido', $details['observaciones']);
        self::assertSame('Contenido no permitido', $details['detalle_factura']);
    }

    public function testHtmlInofensivoEnObservacionesPasa(): void
    {
        $clean = FacturaValidator::validate(['observaciones' => 'Ver <b>nota</b> adjunta'] + self::BASE);
        self::assertSame('Ver <b>nota</b> adjunta', $clean['observaciones']);
    }

    public function testServicioCuotaIdSeCasteaAEnteroONull(): void
    {
        self::assertSame(9, FacturaValidator::validate(['servicio_cuota_id' => '9'] + self::BASE)['servicio_cuota_id']);
        self::assertNull(FacturaValidator::validate(['servicio_cuota_id' => ''] + self::BASE)['servicio_cuota_id']);
        self::assertNull(FacturaValidator::validate(['servicio_cuota_id' => null] + self::BASE)['servicio_cuota_id']);
    }

    // ------------------------------------------------------------
    // Normalización / whitelist
    // ------------------------------------------------------------

    public function testStringsSeRecortanYVaciosPasanANull(): void
    {
        $clean = FacturaValidator::validate([
            'banco' => '  Galicia  ',
            'alias' => '   ',
            'mes_cubierto' => null,
        ] + self::BASE);

        self::assertSame('Galicia', $clean['banco']);
        self::assertNull($clean['alias']);
        self::assertNull($clean['mes_cubierto']);
    }

    public function testClavesFueraDeLaWhitelistNoPasanAlResultado(): void
    {
        $clean = FacturaValidator::validate([
            'id' => 99,
            'created_by' => 1,
            'updated_by' => 1,
            'check_cobranza' => true,
            'deleted_at' => '2026-01-01',
            'archivos' => [],
        ] + self::BASE);

        foreach (['id', 'created_by', 'updated_by', 'check_cobranza', 'deleted_at', 'archivos'] as $k) {
            self::assertArrayNotHasKey($k, $clean, "{$k} no debería pasar al payload limpio");
        }
    }

    public function testElResultadoSoloContieneLasClavesEnviadas(): void
    {
        $clean = FacturaValidator::validate(self::BASE);
        self::assertSame(['numero_factura', 'tipo', 'cuit', 'cliente_id', 'fecha_factura'], array_keys($clean));
    }

    public function testAcumulaTodosLosErroresEnUnaSolaExcepcion(): void
    {
        $details = self::detalles([
            'cuit' => 'x',
            'moneda' => 'EUR',
            'tdc' => -1,
            'numero_mes' => 20,
        ] + array_diff_key(self::BASE, ['cuit' => 1]));

        self::assertArrayHasKey('cuit', $details);
        self::assertArrayHasKey('moneda', $details);
        self::assertArrayHasKey('tdc', $details);
        self::assertArrayHasKey('numero_mes', $details);
    }
}
