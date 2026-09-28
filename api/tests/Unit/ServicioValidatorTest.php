<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Validators\ServicioValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServicioValidatorTest extends TestCase
{
    /** Mantenimiento mínimo válido (mes_calendario, indefinido). */
    private const MANT = [
        'tipo' => 'mantenimiento',
        'cliente_id' => 3,
        'nombre' => 'Soporte mensual',
        'importe_base' => 150000,
        'fecha_inicio' => '2026-06-01',
        'modo_facturacion' => 'mes_calendario',
        'dia_facturacion' => 5,
    ];

    /** Proyecto mínimo válido (2 cuotas 30/70). */
    private const PROY = [
        'tipo' => 'proyecto',
        'cliente_id' => 3,
        'nombre' => 'Implementación CRM',
        'importe_base' => 1000000,
        'fecha_inicio' => '2026-06-01',
        'fecha_fin' => '2026-12-31',
        'cuotas' => [
            ['porcentaje' => 30, 'fecha_prevista' => '2026-06-01', 'etiqueta' => 'Anticipo'],
            ['porcentaje' => 70, 'fecha_prevista' => '2026-12-31'],
        ],
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function detallesCreate(array $data): array
    {
        try {
            ServicioValidator::validateCreate($data);
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function detallesUpdate(array $data): array
    {
        try {
            ServicioValidator::validateUpdate($data);
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    // ------------------------------------------------------------
    // Create: comunes
    // ------------------------------------------------------------

    public function testCreateVacioExigeTipoClienteNombreImporteYFechaInicio(): void
    {
        $details = self::detallesCreate([]);

        foreach (['tipo', 'cliente_id', 'nombre', 'importe_base', 'fecha_inicio'] as $campo) {
            self::assertArrayHasKey($campo, $details, "Falta error en {$campo}");
        }
        // moneda tiene default ARS: no debe figurar como error
        self::assertArrayNotHasKey('moneda', $details);
    }

    public function testLanzaValidationExceptionCon422(): void
    {
        try {
            ServicioValidator::validateCreate([]);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
        }
    }

    public function testTipoDesconocidoEsRechazado(): void
    {
        $details = self::detallesCreate(['tipo' => 'suscripcion'] + self::MANT);
        self::assertStringContainsString('proyecto, mantenimiento', $details['tipo']);
    }

    public function testClienteIdDebeSerPositivo(): void
    {
        self::assertArrayHasKey('cliente_id', self::detallesCreate(['cliente_id' => 0] + self::MANT));
        self::assertArrayHasKey('cliente_id', self::detallesCreate(['cliente_id' => 'abc'] + self::MANT));
    }

    public function testNombreVacioOMuyLargoEsRechazado(): void
    {
        self::assertArrayHasKey('nombre', self::detallesCreate(['nombre' => '  '] + self::MANT));
        self::assertArrayHasKey('nombre', self::detallesCreate(['nombre' => str_repeat('n', 201)] + self::MANT));
    }

    public function testMonedaSoloAdmiteArsYUsd(): void
    {
        self::assertSame('ARS', ServicioValidator::validateCreate(['moneda' => 'ARS'] + self::MANT)['servicio']['moneda']);
        self::assertSame('USD', ServicioValidator::validateCreate(['moneda' => 'USD'] + self::MANT)['servicio']['moneda']);
        self::assertArrayHasKey('moneda', self::detallesCreate(['moneda' => 'EUR'] + self::MANT));
        self::assertArrayHasKey('moneda', self::detallesCreate(['moneda' => 'usd'] + self::MANT));
    }

    public function testMonedaAusenteDefaultARS(): void
    {
        self::assertSame('ARS', ServicioValidator::validateCreate(self::MANT)['servicio']['moneda']);
    }

    /** @return array<string, array{mixed}> */
    public static function importesInvalidos(): array
    {
        return [
            'cero' => [0],
            'negativo' => [-100],
            'texto' => ['cien'],
            'null' => [null],
        ];
    }

    #[DataProvider('importesInvalidos')]
    public function testImporteBaseDebeSerMayorACero(mixed $importe): void
    {
        $details = self::detallesCreate(['importe_base' => $importe] + self::MANT);
        self::assertSame('Requerido, mayor a 0', $details['importe_base']);
    }

    public function testImporteBaseSeCasteaAFloat(): void
    {
        self::assertSame(1500.5, ServicioValidator::validateCreate(['importe_base' => '1500.50'] + self::MANT)['servicio']['importe_base']);
    }

    /** @return array<string, array{mixed, float}> */
    public static function ivasValidos(): array
    {
        return [
            '0' => [0, 0.0],
            '10.5' => [10.5, 10.5],
            '21' => [21, 21.0],
            '21 como string' => ['21', 21.0],
            '10.5 como string' => ['10.5', 10.5],
        ];
    }

    #[DataProvider('ivasValidos')]
    public function testIvaAcepta0105Y21(mixed $iva, float $esperado): void
    {
        self::assertSame($esperado, ServicioValidator::validateCreate(['iva_porcentaje' => $iva] + self::MANT)['servicio']['iva_porcentaje']);
    }

    public function testIvaFueraDeLaListaEsRechazado(): void
    {
        self::assertSame('Permitidos: 0, 10.5 o 21', self::detallesCreate(['iva_porcentaje' => 15] + self::MANT)['iva_porcentaje']);
        self::assertArrayHasKey('iva_porcentaje', self::detallesCreate(['iva_porcentaje' => 27] + self::MANT));
    }

    public function testIvaAusenteDefault21(): void
    {
        self::assertSame(21.0, ServicioValidator::validateCreate(self::MANT)['servicio']['iva_porcentaje']);
    }

    public function testIvaVacioTomaElDefaultYNoCero(): void
    {
        // Un '' desde un form sin completar no es "IVA 0": se ignora y aplica el
        // default 21, igual que validateUpdate lo trata como "sin cambio".
        $clean = ServicioValidator::validateCreate(['iva_porcentaje' => ''] + self::MANT);
        self::assertSame(21.0, $clean['servicio']['iva_porcentaje']);

        // Y un 0 explícito sí es IVA 0 (exento)
        $exento = ServicioValidator::validateCreate(['iva_porcentaje' => 0] + self::MANT);
        self::assertSame(0.0, $exento['servicio']['iva_porcentaje']);
    }

    public function testTipoFacturaDefaultSeValidaContraLosTiposDeFactura(): void
    {
        self::assertArrayHasKey('tipo_factura_default', self::detallesCreate(['tipo_factura_default' => 'X'] + self::MANT));
        self::assertSame('B', ServicioValidator::validateCreate(['tipo_factura_default' => 'B'] + self::MANT)['servicio']['tipo_factura_default']);
        self::assertSame('A', ServicioValidator::validateCreate(self::MANT)['servicio']['tipo_factura_default']);
        self::assertSame('A', ServicioValidator::validateCreate(['tipo_factura_default' => ''] + self::MANT)['servicio']['tipo_factura_default']);
    }

    /** @return array<string, array{string}> */
    public static function fechasInvalidas(): array
    {
        return [
            'formato argentino' => ['01/06/2026'],
            'con barras' => ['2026/06/01'],
            'texto' => ['manana'],
            'anio fuera de rango' => ['1850-01-01'],
            // createFromFormat desborda estas en vez de fallar; el validador las detecta
            '30 de febrero' => ['2026-02-30'],
            'mes 13' => ['2026-13-01'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function testFechaInicioInvalidaEsRechazada(string $fecha): void
    {
        self::assertSame('Requerida (formato YYYY-MM-DD)', self::detallesCreate(['fecha_inicio' => $fecha] + self::MANT)['fecha_inicio']);
    }

    #[DataProvider('fechasInvalidas')]
    public function testFechaFinInvalidaEsRechazada(string $fecha): void
    {
        self::assertSame('Fecha inválida', self::detallesCreate(['fecha_fin' => $fecha] + self::MANT)['fecha_fin']);
    }

    public function testFechaFinDebeSerPosteriorAFechaInicio(): void
    {
        $details = self::detallesCreate(['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-01'] + self::MANT);
        self::assertSame('Debe ser posterior a fecha_inicio', $details['fecha_fin']);

        $details = self::detallesCreate(['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-05-31'] + self::MANT);
        self::assertSame('Debe ser posterior a fecha_inicio', $details['fecha_fin']);
    }

    public function testFrecuenciaAjusteMesesDebeSerEnteroPositivo(): void
    {
        self::assertArrayHasKey('frecuencia_ajuste_meses', self::detallesCreate(['frecuencia_ajuste_meses' => 0] + self::MANT));
        self::assertArrayHasKey('frecuencia_ajuste_meses', self::detallesCreate(['frecuencia_ajuste_meses' => 'seis'] + self::MANT));

        $clean = ServicioValidator::validateCreate(['frecuencia_ajuste_meses' => '6'] + self::MANT);
        self::assertSame(6, $clean['servicio']['frecuencia_ajuste_meses']);
    }

    public function testAvisoDiasPreviosNoPuedeSerNegativo(): void
    {
        self::assertArrayHasKey('aviso_dias_previos', self::detallesCreate(['aviso_dias_previos' => -1] + self::MANT));

        $clean = ServicioValidator::validateCreate(['aviso_dias_previos' => 7] + self::MANT);
        self::assertSame(7, $clean['servicio']['aviso_dias_previos']);
    }

    /** @return array<string, array{string}> */
    public static function camposTextoLibre(): array
    {
        return [
            'descripcion' => ['descripcion'],
            'observaciones' => ['observaciones'],
            'template_factura' => ['template_factura'],
        ];
    }

    #[DataProvider('camposTextoLibre')]
    public function testRechazaScriptsEnTextosLibres(string $campo): void
    {
        $details = self::detallesCreate([$campo => 'x <script>alert(1)</script>'] + self::MANT);
        self::assertSame('Contenido no permitido', $details[$campo]);
    }

    #[DataProvider('camposTextoLibre')]
    public function testTextosLibresSeRecortanYVaciosPasanANull(string $campo): void
    {
        self::assertSame('Texto', ServicioValidator::validateCreate([$campo => '  Texto  '] + self::MANT)['servicio'][$campo]);
        self::assertNull(ServicioValidator::validateCreate([$campo => '   '] + self::MANT)['servicio'][$campo]);
        self::assertNull(ServicioValidator::validateCreate(self::MANT)['servicio'][$campo]);
    }

    // ------------------------------------------------------------
    // Create: proyecto
    // ------------------------------------------------------------

    public function testProyectoValidoDevuelveServicioYCuotasNormalizados(): void
    {
        $out = ServicioValidator::validateCreate(self::PROY);

        self::assertSame(['servicio', 'cuotas'], array_keys($out));
        self::assertSame('proyecto', $out['servicio']['tipo']);
        self::assertSame(3, $out['servicio']['cliente_id']);
        self::assertSame(1000000.0, $out['servicio']['importe_base']);
        self::assertSame('2026-12-31', $out['servicio']['fecha_fin']);

        // Campos de mantenimiento siempre null en proyectos
        foreach (['modo_facturacion', 'dia_facturacion', 'intervalo_dias', 'frecuencia_ajuste_meses', 'aviso_dias_previos'] as $f) {
            self::assertNull($out['servicio'][$f], "{$f} debería ser null en proyecto");
        }

        self::assertSame([
            ['porcentaje' => 30.0, 'fecha_prevista' => '2026-06-01', 'etiqueta' => 'Anticipo'],
            ['porcentaje' => 70.0, 'fecha_prevista' => '2026-12-31', 'etiqueta' => null],
        ], $out['cuotas']);
    }

    public function testProyectoExigeFechaFin(): void
    {
        $data = self::PROY;
        unset($data['fecha_fin']);
        self::assertSame('Requerida para proyectos', self::detallesCreate($data)['fecha_fin']);

        $data['fecha_fin'] = '';
        self::assertSame('Requerida para proyectos', self::detallesCreate($data)['fecha_fin']);
    }

    /** @return array<string, array{string, mixed}> */
    public static function camposDeMantenimiento(): array
    {
        return [
            'modo_facturacion' => ['modo_facturacion', 'mes_calendario'],
            'dia_facturacion' => ['dia_facturacion', 10],
            'intervalo_dias' => ['intervalo_dias', 30],
        ];
    }

    #[DataProvider('camposDeMantenimiento')]
    public function testProyectoNoAdmiteCamposDeMantenimiento(string $campo, mixed $valor): void
    {
        self::assertSame('No aplica para proyectos', self::detallesCreate([$campo => $valor] + self::PROY)[$campo]);
    }

    /** @return array<string, array{mixed}> */
    public static function cuotasAusentes(): array
    {
        return [
            'sin clave' => [null],
            'array vacio' => [[]],
            'string' => ['30,70'],
        ];
    }

    #[DataProvider('cuotasAusentes')]
    public function testProyectoExigeAlMenosUnaCuota(mixed $cuotas): void
    {
        $data = self::PROY;
        if ($cuotas === null) {
            unset($data['cuotas']);
        } else {
            $data['cuotas'] = $cuotas;
        }
        self::assertSame('Requeridas para proyectos (al menos 1)', self::detallesCreate($data)['cuotas']);
    }

    public function testProyectoRechazaPorcentajesQueNoSuman100(): void
    {
        $data = self::PROY;
        $data['cuotas'][1]['porcentaje'] = 60;

        $details = self::detallesCreate($data);
        self::assertSame('Los porcentajes deben sumar 100 (suma actual: 90.00)', $details['cuotas']);
    }

    public function testProyectoToleraDiferenciaDeUnCentesimoEnLaSuma(): void
    {
        $data = self::PROY;
        $data['cuotas'] = [
            ['porcentaje' => 33.33, 'fecha_prevista' => '2026-06-01'],
            ['porcentaje' => 33.33, 'fecha_prevista' => '2026-08-01'],
            ['porcentaje' => 33.34, 'fecha_prevista' => '2026-10-01'],
        ];
        self::assertCount(3, ServicioValidator::validateCreate($data)['cuotas']);

        // 99.995: diferencia de 0.005, dentro de la tolerancia de 0.01
        $data['cuotas'] = [
            ['porcentaje' => 50, 'fecha_prevista' => '2026-06-01'],
            ['porcentaje' => 49.995, 'fecha_prevista' => '2026-08-01'],
        ];
        self::assertCount(2, ServicioValidator::validateCreate($data)['cuotas']);

        // 99.96: fuera de tolerancia
        $data['cuotas'][1]['porcentaje'] = 49.96;
        self::assertArrayHasKey('cuotas', self::detallesCreate($data));
    }

    public function testProyectoConUnaSolaCuotaDel100EsValido(): void
    {
        $data = self::PROY;
        $data['cuotas'] = [['porcentaje' => '100', 'fecha_prevista' => '2026-12-31']];

        $out = ServicioValidator::validateCreate($data);
        self::assertSame(100.0, $out['cuotas'][0]['porcentaje']);
    }

    /** @return array<string, array{mixed}> */
    public static function porcentajesInvalidos(): array
    {
        return [
            'cero' => [0],
            'negativo' => [-10],
            'mayor a 100' => [101],
            'texto' => ['treinta'],
            'ausente' => [null],
        ];
    }

    #[DataProvider('porcentajesInvalidos')]
    public function testProyectoValidaCadaPorcentajeConSuIndice(mixed $porcentaje): void
    {
        $data = self::PROY;
        if ($porcentaje === null) {
            unset($data['cuotas'][0]['porcentaje']);
        } else {
            $data['cuotas'][0]['porcentaje'] = $porcentaje;
        }
        $details = self::detallesCreate($data);
        self::assertSame('Debe ser un número entre 0 (exclusivo) y 100', $details['cuotas.0.porcentaje']);
    }

    public function testProyectoValidaFechaPrevistaDeCadaCuota(): void
    {
        $data = self::PROY;
        $data['cuotas'][1]['fecha_prevista'] = '31/12/2026';
        self::assertSame('Fecha inválida', self::detallesCreate($data)['cuotas.1.fecha_prevista']);

        unset($data['cuotas'][1]['fecha_prevista']);
        self::assertSame('Fecha inválida', self::detallesCreate($data)['cuotas.1.fecha_prevista']);
    }

    public function testProyectoRechazaEtiquetaMuyLargaYCuotaQueNoEsObjeto(): void
    {
        $data = self::PROY;
        $data['cuotas'][0]['etiqueta'] = str_repeat('e', 101);
        self::assertSame('Hasta 100 caracteres', self::detallesCreate($data)['cuotas.0.etiqueta']);

        $data = self::PROY;
        $data['cuotas'][0] = 'anticipo';
        $details = self::detallesCreate($data);
        self::assertSame('Debe ser un objeto', $details['cuotas.0']);
        // La cuota inválida no suma: además falla la suma de porcentajes
        self::assertArrayHasKey('cuotas', $details);
    }

    public function testProyectoRecortaEtiquetasDeCuotas(): void
    {
        $data = self::PROY;
        $data['cuotas'][0]['etiqueta'] = '  Anticipo  ';
        self::assertSame('Anticipo', ServicioValidator::validateCreate($data)['cuotas'][0]['etiqueta']);
    }

    // ------------------------------------------------------------
    // Create: mantenimiento
    // ------------------------------------------------------------

    public function testMantenimientoValidoIndefinidoDevuelveFechaFinNullYSinCuotas(): void
    {
        $out = ServicioValidator::validateCreate(self::MANT);

        self::assertSame('mantenimiento', $out['servicio']['tipo']);
        self::assertNull($out['servicio']['fecha_fin']);
        self::assertSame('mes_calendario', $out['servicio']['modo_facturacion']);
        self::assertSame(5, $out['servicio']['dia_facturacion']);
        self::assertNull($out['servicio']['intervalo_dias']);
        self::assertSame([], $out['cuotas']);
    }

    public function testMantenimientoFechaFinVaciaSeTrataComoIndefinido(): void
    {
        self::assertNull(ServicioValidator::validateCreate(['fecha_fin' => ''] + self::MANT)['servicio']['fecha_fin']);
        self::assertNull(ServicioValidator::validateCreate(['fecha_fin' => null] + self::MANT)['servicio']['fecha_fin']);
    }

    public function testMantenimientoConFechaFinValidaLaConserva(): void
    {
        self::assertSame('2027-05-31', ServicioValidator::validateCreate(['fecha_fin' => '2027-05-31'] + self::MANT)['servicio']['fecha_fin']);
    }

    public function testMantenimientoExigeModoFacturacion(): void
    {
        $data = self::MANT;
        unset($data['modo_facturacion']);
        self::assertStringContainsString('mes_calendario, intervalo_dias', self::detallesCreate($data)['modo_facturacion']);

        $data['modo_facturacion'] = 'semanal';
        self::assertArrayHasKey('modo_facturacion', self::detallesCreate($data));
    }

    /** @return array<string, array{mixed, string}> */
    public static function diasFacturacionInvalidos(): array
    {
        return [
            'ausente' => [null, 'Requerido en modo mes_calendario (1-31)'],
            'cero' => [0, 'Requerido en modo mes_calendario (1-31)'],
            'vacio' => ['', 'Requerido en modo mes_calendario (1-31)'],
            '32' => [32, 'Debe estar entre 1 y 31'],
            'negativo' => [-1, 'Debe estar entre 1 y 31'],
        ];
    }

    #[DataProvider('diasFacturacionInvalidos')]
    public function testMesCalendarioExigeDiaFacturacionEntre1Y31(mixed $dia, string $mensaje): void
    {
        $data = self::MANT;
        if ($dia === null) {
            unset($data['dia_facturacion']);
        } else {
            $data['dia_facturacion'] = $dia;
        }
        self::assertSame($mensaje, self::detallesCreate($data)['dia_facturacion']);
    }

    public function testMesCalendarioAceptaDia1Y31(): void
    {
        self::assertSame(1, ServicioValidator::validateCreate(['dia_facturacion' => 1] + self::MANT)['servicio']['dia_facturacion']);
        self::assertSame(31, ServicioValidator::validateCreate(['dia_facturacion' => '31'] + self::MANT)['servicio']['dia_facturacion']);
    }

    public function testMesCalendarioNoAdmiteIntervaloDias(): void
    {
        self::assertSame('No aplica en modo mes_calendario', self::detallesCreate(['intervalo_dias' => 30] + self::MANT)['intervalo_dias']);
    }

    public function testIntervaloDiasExigeIntervaloPositivo(): void
    {
        $base = ['modo_facturacion' => 'intervalo_dias', 'dia_facturacion' => null] + self::MANT;

        self::assertSame('Requerido en modo intervalo_dias (entero >= 1)', self::detallesCreate($base)['intervalo_dias']);
        self::assertArrayHasKey('intervalo_dias', self::detallesCreate(['intervalo_dias' => 0] + $base));
        self::assertArrayHasKey('intervalo_dias', self::detallesCreate(['intervalo_dias' => -7] + $base));

        $out = ServicioValidator::validateCreate(['intervalo_dias' => '15'] + $base);
        self::assertSame('intervalo_dias', $out['servicio']['modo_facturacion']);
        self::assertSame(15, $out['servicio']['intervalo_dias']);
        self::assertNull($out['servicio']['dia_facturacion']);
    }

    public function testIntervaloDiasNoAdmiteDiaFacturacion(): void
    {
        $data = ['modo_facturacion' => 'intervalo_dias', 'intervalo_dias' => 30, 'dia_facturacion' => 5] + self::MANT;
        self::assertSame('No aplica en modo intervalo_dias', self::detallesCreate($data)['dia_facturacion']);
    }

    public function testMantenimientoNoAdmiteCuotasManuales(): void
    {
        $data = ['cuotas' => [['porcentaje' => 100, 'fecha_prevista' => '2026-06-01']]] + self::MANT;
        self::assertSame(
            'No se ingresan manualmente en mantenimiento (se generan automáticamente)',
            self::detallesCreate($data)['cuotas'],
        );
    }

    public function testMantenimientoNormalizaFrecuenciaYAvisoANullSiNoVienen(): void
    {
        $out = ServicioValidator::validateCreate(self::MANT);
        self::assertNull($out['servicio']['frecuencia_ajuste_meses']);
        self::assertNull($out['servicio']['aviso_dias_previos']);
    }

    public function testCreateNoPasaCamposFueraDeLaWhitelist(): void
    {
        $out = ServicioValidator::validateCreate([
            'id' => 1,
            'estado' => 'cancelado',
            'created_by' => 1,
            'pausado_at' => '2026-01-01',
        ] + self::MANT);

        foreach (['id', 'estado', 'created_by', 'pausado_at'] as $k) {
            self::assertArrayNotHasKey($k, $out['servicio']);
        }
    }

    // ------------------------------------------------------------
    // Update
    // ------------------------------------------------------------

    public function testUpdateVacioDevuelveArrayVacio(): void
    {
        self::assertSame([], ServicioValidator::validateUpdate([]));
    }

    public function testUpdateAceptaParcialYNormalizaTipos(): void
    {
        $clean = ServicioValidator::validateUpdate([
            'nombre' => '  Nuevo nombre ',
            'importe_base' => '2000.50',
            'iva_porcentaje' => '10.5',
            'dia_facturacion' => '10',
            'frecuencia_ajuste_meses' => '3',
            'aviso_dias_previos' => '5',
            'descripcion' => '',
        ]);

        self::assertSame('Nuevo nombre', $clean['nombre']);
        self::assertSame(2000.5, $clean['importe_base']);
        self::assertSame(10.5, $clean['iva_porcentaje']);
        self::assertSame(10, $clean['dia_facturacion']);
        self::assertSame(3, $clean['frecuencia_ajuste_meses']);
        self::assertSame(5, $clean['aviso_dias_previos']);
        self::assertNull($clean['descripcion']);
    }

    /** @return array<string, array{string}> */
    public static function camposProhibidosEnUpdate(): array
    {
        return [
            'cliente_id' => ['cliente_id'],
            'tipo' => ['tipo'],
            'moneda' => ['moneda'],
        ];
    }

    #[DataProvider('camposProhibidosEnUpdate')]
    public function testUpdateProhibeCambiarClienteTipoYMoneda(string $campo): void
    {
        $details = self::detallesUpdate([$campo => 'x']);
        self::assertSame('No se puede modificar después de crear el servicio', $details[$campo]);
    }

    public function testUpdateValidaNombreImporteEIva(): void
    {
        self::assertArrayHasKey('nombre', self::detallesUpdate(['nombre' => '']));
        self::assertSame('Debe ser numérico y > 0', self::detallesUpdate(['importe_base' => 0])['importe_base']);
        self::assertArrayHasKey('importe_base', self::detallesUpdate(['importe_base' => 'abc']));
        self::assertArrayHasKey('iva_porcentaje', self::detallesUpdate(['iva_porcentaje' => 15]));
        self::assertArrayHasKey('tipo_factura_default', self::detallesUpdate(['tipo_factura_default' => 'Z']));
    }

    public function testUpdateIvaYTipoFacturaVaciosSeIgnoran(): void
    {
        $clean = ServicioValidator::validateUpdate(['iva_porcentaje' => '', 'tipo_factura_default' => null]);
        self::assertSame([], $clean);
    }

    public function testUpdateValidaRangosDeDiaIntervaloYFrecuencia(): void
    {
        self::assertSame('Debe estar entre 1 y 31', self::detallesUpdate(['dia_facturacion' => 32])['dia_facturacion']);
        self::assertArrayHasKey('dia_facturacion', self::detallesUpdate(['dia_facturacion' => 0]));
        self::assertSame('Debe ser entero positivo', self::detallesUpdate(['intervalo_dias' => 0])['intervalo_dias']);
        self::assertSame('Debe ser entero positivo', self::detallesUpdate(['frecuencia_ajuste_meses' => 'x'])['frecuencia_ajuste_meses']);
    }

    public function testUpdateValidaFechas(): void
    {
        self::assertSame('Fecha inválida', self::detallesUpdate(['fecha_inicio' => '01/06/2026'])['fecha_inicio']);
        self::assertSame('Fecha inválida', self::detallesUpdate(['fecha_fin' => 'nunca'])['fecha_fin']);

        $clean = ServicioValidator::validateUpdate(['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2027-06-01']);
        self::assertSame(['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2027-06-01'], $clean);
    }

    public function testUpdatePermiteVolverUnMantenimientoAIndefinidoConFechaFinNull(): void
    {
        self::assertSame(['fecha_fin' => null], ServicioValidator::validateUpdate(['fecha_fin' => null]));
        self::assertSame(['fecha_fin' => null], ServicioValidator::validateUpdate(['fecha_fin' => '']));
    }

    public function testUpdateEnterosVaciosPasanANull(): void
    {
        $clean = ServicioValidator::validateUpdate(['intervalo_dias' => '', 'frecuencia_ajuste_meses' => null]);
        self::assertSame(['intervalo_dias' => null, 'frecuencia_ajuste_meses' => null], $clean);
    }

    public function testUpdateNoPasaCamposFueraDeLaWhitelist(): void
    {
        $clean = ServicioValidator::validateUpdate([
            'nombre' => 'X',
            'estado' => 'cancelado',
            'cuotas' => [],
            'created_by' => 9,
            'modo_facturacion' => 'intervalo_dias',
        ]);

        self::assertSame(['nombre' => 'X'], $clean);
    }
}
