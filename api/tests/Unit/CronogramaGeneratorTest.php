<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use DateTimeImmutable;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Services\CronogramaGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests puros del generador de cronogramas: el Servicio se arma in-memory y
 * nunca se persiste ni se consulta.
 *
 * Se usa setRawAttributes() en vez de fill(): con fill(), los casts `date` de
 * fecha_inicio/fecha_fin pasan por Model::getDateFormat(), que pide la conexión
 * (resolver) y no existe en tests unitarios. Al leer, el cast 'date' con
 * strings 'Y-m-d' no toca la conexión.
 */
final class CronogramaGeneratorTest extends TestCase
{
    /** @param array<string,mixed> $attrs */
    private static function servicio(array $attrs): Servicio
    {
        $s = new Servicio();
        $s->setRawAttributes(array_merge([
            'tipo' => Servicio::TIPO_MANTENIMIENTO,
            'nombre' => 'Test',
            'moneda' => 'ARS',
            'importe_base' => '100000.00',
            'fecha_fin' => null,
            'modo_facturacion' => null,
            'dia_facturacion' => null,
            'intervalo_dias' => null,
        ], $attrs));
        return $s;
    }

    /** @param array<int, array<string,mixed>> $cuotas */
    private static function fechas(array $cuotas): array
    {
        return array_column($cuotas, 'fecha_prevista');
    }

    /** @param array<int, array<string,mixed>> $cuotas */
    private static function etiquetas(array $cuotas): array
    {
        return array_column($cuotas, 'etiqueta');
    }

    // ============================================================
    // proximoDiaDelMes
    // ============================================================

    /** @return array<string, array{string, int, string}> */
    public static function casosProximoDia(): array
    {
        return [
            'mismo mes, dia posterior' => ['2026-06-01', 15, '2026-06-15'],
            'mismo dia exacto' => ['2026-06-05', 5, '2026-06-05'],
            'dia ya pasado -> mes siguiente' => ['2026-06-10', 5, '2026-07-05'],
            'dia 31 en enero' => ['2026-01-31', 31, '2026-01-31'],
            'dia 31 en febrero no bisiesto -> 28' => ['2026-02-01', 31, '2026-02-28'],
            'dia 31 en febrero bisiesto -> 29' => ['2024-02-01', 31, '2024-02-29'],
            'dia 29 en febrero bisiesto' => ['2024-02-01', 29, '2024-02-29'],
            'dia 29 en febrero no bisiesto -> 28' => ['2026-02-01', 29, '2026-02-28'],
            'desde el 29 de febrero bisiesto, dia 29' => ['2024-02-29', 29, '2024-02-29'],
            'desde el 29 de febrero bisiesto, dia 15 -> marzo' => ['2024-02-29', 15, '2024-03-15'],
            'desde el 28 de febrero, dia 31 -> mismo 28 (ultimo dia)' => ['2026-02-28', 31, '2026-02-28'],
            'dia 31 en abril -> 30' => ['2026-04-01', 31, '2026-04-30'],
            'cambio de anio' => ['2026-12-20', 10, '2027-01-10'],
            'ultimo dia del anio' => ['2026-12-31', 31, '2026-12-31'],
            'diciembre pasado el dia -> enero' => ['2026-12-31', 30, '2027-01-30'],
        ];
    }

    #[DataProvider('casosProximoDia')]
    public function testProximoDiaDelMes(string $desde, int $dia, string $esperado): void
    {
        $out = CronogramaGenerator::proximoDiaDelMes(new DateTimeImmutable($desde), $dia);
        self::assertSame($esperado, $out->format('Y-m-d'));
    }

    public function testProximoDiaDelMesAcotaElDiaEntre1Y31(): void
    {
        // 0 se trata como 1: desde el 15 ya pasó -> 1 del mes siguiente
        self::assertSame('2026-05-01', CronogramaGenerator::proximoDiaDelMes(new DateTimeImmutable('2026-04-15'), 0)->format('Y-m-d'));
        // 40 se trata como 31: abril tiene 30
        self::assertSame('2026-04-30', CronogramaGenerator::proximoDiaDelMes(new DateTimeImmutable('2026-04-01'), 40)->format('Y-m-d'));
        self::assertSame('2026-04-30', CronogramaGenerator::proximoDiaDelMes(new DateTimeImmutable('2026-04-01'), 31)->format('Y-m-d'));
    }

    public function testProximoDiaDelMesDevuelveUnaFechaSinHora(): void
    {
        $out = CronogramaGenerator::proximoDiaDelMes(new DateTimeImmutable('2026-06-10 15:45:00'), 20);
        self::assertSame('2026-06-20 00:00:00', $out->format('Y-m-d H:i:s'));
    }

    // ============================================================
    // PROYECTO
    // ============================================================

    public function testProyectoGeneraUnaCuotaPorPorcentaje(): void
    {
        $s = self::servicio(['tipo' => Servicio::TIPO_PROYECTO, 'importe_base' => '1000000.00']);

        $cuotas = CronogramaGenerator::generar($s, [
            ['porcentaje' => 30, 'fecha_prevista' => '2026-06-01', 'etiqueta' => 'Anticipo'],
            ['porcentaje' => 40, 'fecha_prevista' => '2026-07-15', 'etiqueta' => 'Hito 1'],
            ['porcentaje' => 30, 'fecha_prevista' => '2026-09-01', 'etiqueta' => 'Cierre'],
        ]);

        self::assertCount(3, $cuotas);
        self::assertSame([
            'numero_cuota' => 1,
            'total_cuotas' => 3,
            'porcentaje' => 30.0,
            'importe' => 300000.0,
            'fecha_prevista' => '2026-06-01',
            'etiqueta' => 'Anticipo',
            'es_proporcional' => false,
            'dias_cubiertos' => null,
        ], $cuotas[0]);
        self::assertSame(400000.0, $cuotas[1]['importe']);
        self::assertSame(300000.0, $cuotas[2]['importe']);
        self::assertSame([1, 2, 3], array_column($cuotas, 'numero_cuota'));
        self::assertSame([3, 3, 3], array_column($cuotas, 'total_cuotas'));
        self::assertSame(['Anticipo', 'Hito 1', 'Cierre'], self::etiquetas($cuotas));
    }

    public function testProyectoSinEtiquetaUsaCuotaNDeM(): void
    {
        $s = self::servicio(['tipo' => Servicio::TIPO_PROYECTO, 'importe_base' => '1000.00']);

        $cuotas = CronogramaGenerator::generar($s, [
            ['porcentaje' => 50, 'fecha_prevista' => '2026-06-01'],
            ['porcentaje' => 50, 'fecha_prevista' => '2026-07-01', 'etiqueta' => '   '],
        ]);

        self::assertSame(['Cuota 1 de 2', 'Cuota 2 de 2'], self::etiquetas($cuotas));
    }

    public function testProyectoRecortaEtiquetasYCasteaPorcentajesString(): void
    {
        $s = self::servicio(['tipo' => Servicio::TIPO_PROYECTO, 'importe_base' => '1000.00']);

        $cuotas = CronogramaGenerator::generar($s, [
            ['porcentaje' => '100', 'fecha_prevista' => '2026-06-01', 'etiqueta' => '  Único pago  '],
        ]);

        self::assertSame(100.0, $cuotas[0]['porcentaje']);
        self::assertSame(1000.0, $cuotas[0]['importe']);
        self::assertSame('Único pago', $cuotas[0]['etiqueta']);
        self::assertSame(1, $cuotas[0]['total_cuotas']);
    }

    public function testProyectoRedondeaImportesADosDecimalesYLaSumaCoincide(): void
    {
        $s = self::servicio(['tipo' => Servicio::TIPO_PROYECTO, 'importe_base' => '1000.00']);

        $cuotas = CronogramaGenerator::generar($s, [
            ['porcentaje' => 33.33, 'fecha_prevista' => '2026-06-01'],
            ['porcentaje' => 33.33, 'fecha_prevista' => '2026-07-01'],
            ['porcentaje' => 33.34, 'fecha_prevista' => '2026-08-01'],
        ]);

        self::assertSame([333.3, 333.3, 333.4], array_column($cuotas, 'importe'));
        self::assertEqualsWithDelta(1000.0, array_sum(array_column($cuotas, 'importe')), 0.001);
    }

    public function testProyectoRedondeaHalfUpADosDecimales(): void
    {
        // 1234.567 * 12.5% = 154.320875 -> 154.32 ; 87.5% = 1080.246125 -> 1080.25
        $s = self::servicio(['tipo' => Servicio::TIPO_PROYECTO, 'importe_base' => '1234.57']);

        $cuotas = CronogramaGenerator::generar($s, [
            ['porcentaje' => 12.5, 'fecha_prevista' => '2026-06-01'],
            ['porcentaje' => 87.5, 'fecha_prevista' => '2026-07-01'],
        ]);

        self::assertSame(154.32, $cuotas[0]['importe']);
        self::assertSame(1080.25, $cuotas[1]['importe']);
        self::assertEqualsWithDelta(1234.57, $cuotas[0]['importe'] + $cuotas[1]['importe'], 0.001);
    }

    public function testProyectoIgnoraWindowMonthsYDevuelveVacioSinCuotas(): void
    {
        $s = self::servicio(['tipo' => Servicio::TIPO_PROYECTO]);

        self::assertSame([], CronogramaGenerator::generar($s, [], 24));
        self::assertCount(1, CronogramaGenerator::generar($s, [['porcentaje' => 100, 'fecha_prevista' => '2026-06-01']], 24));
    }

    // ============================================================
    // MANTENIMIENTO mes_calendario — indefinido
    // ============================================================

    public function testMesCalendarioIndefinidoGeneraRollingWindowDe12Cuotas(): void
    {
        $s = self::servicio([
            'importe_base' => '500.00',
            'moneda' => 'USD',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => null,
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 15,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertCount(CronogramaGenerator::DEFAULT_WINDOW_MONTHS, $cuotas);
        self::assertSame([
            '2026-06-15', '2026-07-15', '2026-08-15', '2026-09-15', '2026-10-15', '2026-11-15',
            '2026-12-15', '2027-01-15', '2027-02-15', '2027-03-15', '2027-04-15', '2027-05-15',
        ], self::fechas($cuotas));
        self::assertSame(range(1, 12), array_column($cuotas, 'numero_cuota'));

        foreach ($cuotas as $c) {
            self::assertNull($c['total_cuotas']);
            self::assertNull($c['porcentaje']);
            self::assertSame(500.0, $c['importe']);
            self::assertFalse($c['es_proporcional']);
            self::assertNull($c['dias_cubiertos']);
        }
    }

    public function testMesCalendarioIndefinidoEtiquetaSoloConMesCalendario(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-06-01',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 15,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertSame([
            'Junio 2026', 'Julio 2026', 'Agosto 2026', 'Septiembre 2026', 'Octubre 2026', 'Noviembre 2026',
            'Diciembre 2026', 'Enero 2027', 'Febrero 2027', 'Marzo 2027', 'Abril 2027', 'Mayo 2027',
        ], self::etiquetas($cuotas));
    }

    public function testMesCalendarioIndefinidoRespetaWindowMonthsPersonalizado(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-06-01',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 1,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 3);

        self::assertSame(['2026-06-01', '2026-07-01', '2026-08-01'], self::fechas($cuotas));
    }

    public function testMesCalendarioIndefinidoCruzaElCambioDeAnio(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-11-20',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 10,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 3);

        self::assertSame(['2026-12-10', '2027-01-10', '2027-02-10'], self::fechas($cuotas));
        self::assertSame(['Diciembre 2026', 'Enero 2027', 'Febrero 2027'], self::etiquetas($cuotas));
    }

    public function testMesCalendarioIndefinidoDia31AjustaAlUltimoDiaDeCadaMes(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-01-01',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 31,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 6);

        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
            self::fechas($cuotas),
        );
    }

    public function testMesCalendarioDia29EnAnioBisiestoCaeEl29DeFebrero(): void
    {
        $bisiesto = self::servicio([
            'fecha_inicio' => '2024-01-01',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 29,
        ]);
        self::assertSame(['2024-01-29', '2024-02-29', '2024-03-29'], self::fechas(CronogramaGenerator::generar($bisiesto, [], 3)));

        $noBisiesto = self::servicio([
            'fecha_inicio' => '2026-01-01',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 29,
        ]);
        self::assertSame(['2026-01-29', '2026-02-28', '2026-03-29'], self::fechas(CronogramaGenerator::generar($noBisiesto, [], 3)));
    }

    public function testMesCalendarioSinDiaFacturacionUsaElDia1(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-06-10',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => null,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 2);

        self::assertSame(['2026-07-01', '2026-08-01'], self::fechas($cuotas));
    }

    public function testMesCalendarioAceptaFechaInicioComoCarbonPorElCastDelModelo(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-06-05',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 5,
        ]);

        // El cast 'date' devuelve un Carbon; el generador lo convierte a DateTimeImmutable
        self::assertInstanceOf(\DateTimeInterface::class, $s->fecha_inicio);
        self::assertSame('2026-06-05', CronogramaGenerator::generar($s, [], 1)[0]['fecha_prevista']);
    }

    // ============================================================
    // MANTENIMIENTO mes_calendario — con fecha_fin
    // ============================================================

    public function testMesCalendarioConFechaFinGeneraUltimaCuotaProporcional(): void
    {
        // inicio 2026-06-10, fin 2026-09-15, día 5: cuotas 07-05, 08-05, 09-05
        // la última cubre 09-05..09-15 = 10 días de 30 esperados
        $s = self::servicio([
            'importe_base' => '100000.00',
            'fecha_inicio' => '2026-06-10',
            'fecha_fin' => '2026-09-15',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 5,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertCount(3, $cuotas);
        self::assertSame(['2026-07-05', '2026-08-05', '2026-09-05'], self::fechas($cuotas));
        self::assertSame([3, 3, 3], array_column($cuotas, 'total_cuotas'));

        self::assertFalse($cuotas[0]['es_proporcional']);
        self::assertSame(100000.0, $cuotas[0]['importe']);
        self::assertNull($cuotas[0]['dias_cubiertos']);

        self::assertTrue($cuotas[2]['es_proporcional']);
        self::assertSame(10, $cuotas[2]['dias_cubiertos']);
        self::assertSame(33333.33, $cuotas[2]['importe']);

        self::assertSame([
            '1 de 3 — Julio 2026',
            '2 de 3 — Agosto 2026',
            '3 de 3 — Septiembre 2026 (proporcional 10 días)',
        ], self::etiquetas($cuotas));
    }

    public function testMesCalendarioConFechaFinIgualAUnaCuotaNoGeneraCuotaDeCeroDias(): void
    {
        // inicio 01-01, fin 04-01, día 1: 01-01, 02-01, 03-01 completas; la de 04-01 cubriría 0 días
        $s = self::servicio([
            'importe_base' => '1000.00',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-04-01',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 1,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], self::fechas($cuotas));
        // total_cuotas y etiquetas se recalculan tras descartar la de 0 días
        self::assertSame([3, 3, 3], array_column($cuotas, 'total_cuotas'));
        self::assertSame(['1 de 3 — Enero 2026', '2 de 3 — Febrero 2026', '3 de 3 — Marzo 2026'], self::etiquetas($cuotas));
        self::assertSame([1000.0, 1000.0, 1000.0], array_column($cuotas, 'importe'));
        self::assertSame([false, false, false], array_column($cuotas, 'es_proporcional'));
    }

    public function testMesCalendarioDia31CruzandoFebreroConFechaFin(): void
    {
        // inicio 2026-01-15, fin 2026-05-31, día 31 -> 01-31, 02-28, 03-31, 04-30, (05-31 = 0 días, descartada)
        $s = self::servicio([
            'importe_base' => '50000.00',
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-05-31',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 31,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], self::fechas($cuotas));
        self::assertSame([1, 2, 3, 4], array_column($cuotas, 'numero_cuota'));
        self::assertSame([4, 4, 4, 4], array_column($cuotas, 'total_cuotas'));
        self::assertSame([false, false, false, false], array_column($cuotas, 'es_proporcional'));
        self::assertSame('4 de 4 — Abril 2026', $cuotas[3]['etiqueta']);
    }

    public function testMesCalendarioConFechaFinAntesDeLaPrimeraCuotaDevuelveVacio(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-06-10',
            'fecha_fin' => '2026-06-20',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 5, // primera sería 07-05 > fin
        ]);

        self::assertSame([], CronogramaGenerator::generar($s));
    }

    public function testMesCalendarioProporcionalRedondeaADosDecimales(): void
    {
        // inicio 2026-01-01, fin 2026-02-15, día 1: 01-01 completa; 02-01 cubre 14 de 28 días -> 50%
        $s = self::servicio([
            'importe_base' => '999.99',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-02-15',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 1,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertCount(2, $cuotas);
        self::assertSame(14, $cuotas[1]['dias_cubiertos']);
        self::assertSame(500.0, $cuotas[1]['importe']); // round(999.99 * 14 / 28, 2) = 500.0
        self::assertSame('2 de 2 — Febrero 2026 (proporcional 14 días)', $cuotas[1]['etiqueta']);
    }

    // ============================================================
    // MANTENIMIENTO intervalo_dias
    // ============================================================

    public function testIntervaloDiasConFechaFinGeneraCompletasMasProporcional(): void
    {
        // 2026-01-01 -> 2026-04-15 = 104 días / 30 = 3 completas + 14 días
        $s = self::servicio([
            'importe_base' => '60000.00',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-04-15',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 30,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertCount(4, $cuotas);
        self::assertSame(['2026-01-01', '2026-01-31', '2026-03-02', '2026-04-01'], self::fechas($cuotas));
        self::assertSame([4, 4, 4, 4], array_column($cuotas, 'total_cuotas'));
        self::assertSame([60000.0, 60000.0, 60000.0, 28000.0], array_column($cuotas, 'importe'));
        self::assertSame([false, false, false, true], array_column($cuotas, 'es_proporcional'));
        self::assertSame([null, null, null, 14], array_column($cuotas, 'dias_cubiertos'));
        self::assertSame([
            'Cuota 1 de 4',
            'Cuota 2 de 4',
            'Cuota 3 de 4',
            'Cuota 4 de 4 (proporcional 14 días)',
        ], self::etiquetas($cuotas));
        self::assertSame([null, null, null, null], array_column($cuotas, 'porcentaje'));
    }

    public function testIntervaloDiasConDivisionExactaNoGeneraProporcional(): void
    {
        // 2026-01-01 -> 2026-01-31 = 30 días / 15 = 2 cuotas justas
        $s = self::servicio([
            'importe_base' => '1000.00',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-01-31',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 15,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertSame(['2026-01-01', '2026-01-16'], self::fechas($cuotas));
        self::assertSame([2, 2], array_column($cuotas, 'total_cuotas'));
        self::assertSame([false, false], array_column($cuotas, 'es_proporcional'));
        self::assertSame(['Cuota 1 de 2', 'Cuota 2 de 2'], self::etiquetas($cuotas));
    }

    public function testIntervaloDiasMasCortoQueElIntervaloGeneraSoloUnaProporcional(): void
    {
        // 10 días de un intervalo de 30 -> 1 cuota proporcional de 1/3
        $s = self::servicio([
            'importe_base' => '3000.00',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-11',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 30,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertCount(1, $cuotas);
        self::assertSame('2026-06-01', $cuotas[0]['fecha_prevista']);
        self::assertTrue($cuotas[0]['es_proporcional']);
        self::assertSame(10, $cuotas[0]['dias_cubiertos']);
        self::assertSame(1000.0, $cuotas[0]['importe']);
        self::assertSame('Cuota 1 de 1 (proporcional 10 días)', $cuotas[0]['etiqueta']);
    }

    public function testIntervaloDiasConFechaFinNoPosteriorDevuelveVacio(): void
    {
        $base = [
            'fecha_inicio' => '2026-06-01',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 30,
        ];

        self::assertSame([], CronogramaGenerator::generar(self::servicio(['fecha_fin' => '2026-06-01'] + $base)));
        self::assertSame([], CronogramaGenerator::generar(self::servicio(['fecha_fin' => '2026-05-01'] + $base)));
    }

    public function testIntervaloDiasIndefinidoGeneraCuotasEquivalentesAlWindow(): void
    {
        // 12 meses * 30 / 30 = 12 cuotas
        $s = self::servicio([
            'importe_base' => '2500.00',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => null,
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 30,
        ]);

        $cuotas = CronogramaGenerator::generar($s);

        self::assertCount(12, $cuotas);
        self::assertSame('2026-06-01', $cuotas[0]['fecha_prevista']);
        self::assertSame('2026-07-01', $cuotas[1]['fecha_prevista']);
        self::assertSame('2027-04-27', $cuotas[11]['fecha_prevista']); // 2026-06-01 + 330 días
        self::assertSame(range(1, 12), array_column($cuotas, 'numero_cuota'));
        self::assertSame(array_fill(0, 12, null), array_column($cuotas, 'total_cuotas'));
        self::assertSame(array_fill(0, 12, 2500.0), array_column($cuotas, 'importe'));
        self::assertSame(array_fill(0, 12, false), array_column($cuotas, 'es_proporcional'));
        self::assertSame('Cuota 1', $cuotas[0]['etiqueta']);
        self::assertSame('Cuota 12', $cuotas[11]['etiqueta']);
    }

    public function testIntervaloDiasIndefinidoRedondeaHaciaArribaLaCantidad(): void
    {
        // 1 mes * 30 / 7 = 4.28 -> 5 cuotas semanales
        $s = self::servicio([
            'fecha_inicio' => '2026-06-01',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 7,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 1);

        self::assertSame(['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29'], self::fechas($cuotas));
    }

    public function testIntervaloDiasCruzaElCambioDeAnioYFebreroBisiesto(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2023-12-31',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 30,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 2); // 60/30 = 2

        self::assertSame(['2023-12-31', '2024-01-30'], self::fechas($cuotas));

        $s2 = self::servicio([
            'fecha_inicio' => '2024-02-28',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 1,
        ]);
        $cuotas2 = CronogramaGenerator::generar($s2, [], 1); // 30 cuotas diarias
        self::assertSame('2024-02-29', $cuotas2[1]['fecha_prevista']);
        self::assertSame('2024-03-01', $cuotas2[2]['fecha_prevista']);
    }

    public function testIntervaloDiasSinValorUsa30PorDefecto(): void
    {
        $s = self::servicio([
            'fecha_inicio' => '2026-06-01',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => null,
        ]);

        $cuotas = CronogramaGenerator::generar($s, [], 2);

        self::assertSame(['2026-06-01', '2026-07-01'], self::fechas($cuotas));
    }

    // ============================================================
    // Estructura común
    // ============================================================

    public function testTodasLasCuotasTienenLasMismasClaves(): void
    {
        $claves = ['numero_cuota', 'total_cuotas', 'porcentaje', 'importe', 'fecha_prevista', 'etiqueta', 'es_proporcional', 'dias_cubiertos'];

        $proyecto = CronogramaGenerator::generar(
            self::servicio(['tipo' => Servicio::TIPO_PROYECTO]),
            [['porcentaje' => 100, 'fecha_prevista' => '2026-06-01']],
        );
        $mes = CronogramaGenerator::generar(self::servicio([
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-08-15',
            'modo_facturacion' => Servicio::MODO_MES_CALENDARIO,
            'dia_facturacion' => 1,
        ]));
        $intervalo = CronogramaGenerator::generar(self::servicio([
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-08-15',
            'modo_facturacion' => Servicio::MODO_INTERVALO_DIAS,
            'intervalo_dias' => 30,
        ]));

        foreach (array_merge($proyecto, $mes, $intervalo) as $cuota) {
            self::assertSame($claves, array_keys($cuota));
        }
    }
}
