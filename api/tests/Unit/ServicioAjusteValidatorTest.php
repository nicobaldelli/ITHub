<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\ServicioAjuste;
use ITHub\Api\Validators\ServicioAjusteValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServicioAjusteValidatorTest extends TestCase
{
    /** Ajuste programado por monto absoluto, mínimo válido. */
    private const BASE = [
        'tipo' => 'programado',
        'modo' => 'monto',
        'valor' => 180000,
        'fecha_aplicacion' => '2026-07-01',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function detalles(array $data): array
    {
        try {
            ServicioAjusteValidator::validateCreate($data);
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    // ------------------------------------------------------------
    // Casos válidos
    // ------------------------------------------------------------

    public function testAjustePorMontoValidoDevuelveEstructuraCompleta(): void
    {
        $clean = ServicioAjusteValidator::validateCreate(self::BASE);

        self::assertSame([
            'tipo' => 'programado',
            'modo' => 'monto',
            'valor' => 180000.0,
            'fecha_aplicacion' => '2026-07-01',
            'cuota_desde_id' => null,
            'observaciones' => null,
        ], $clean);
    }

    public function testAjustePorPorcentajeAceptaPositivosNegativosYCero(): void
    {
        $base = ['modo' => 'porcentaje'] + self::BASE;

        self::assertSame(15.0, ServicioAjusteValidator::validateCreate(['valor' => 15] + $base)['valor']);
        self::assertSame(-5.0, ServicioAjusteValidator::validateCreate(['valor' => -5] + $base)['valor']);
        self::assertSame(0.0, ServicioAjusteValidator::validateCreate(['valor' => 0] + $base)['valor']);
        self::assertSame(12.5, ServicioAjusteValidator::validateCreate(['valor' => '12.5'] + $base)['valor']);
    }

    /** @return array<string, array{string}> */
    public static function tiposValidos(): array
    {
        return [
            'programado' => ['programado'],
            'espontaneo' => ['espontaneo'],
        ];
    }

    #[DataProvider('tiposValidos')]
    public function testAceptaTiposProgramadoYEspontaneo(string $tipo): void
    {
        self::assertContains($tipo, ServicioAjuste::TIPOS);
        self::assertSame($tipo, ServicioAjusteValidator::validateCreate(['tipo' => $tipo] + self::BASE)['tipo']);
    }

    public function testValorComoStringNumericoSeCasteaAFloat(): void
    {
        self::assertSame(1500.5, ServicioAjusteValidator::validateCreate(['valor' => '1500.50'] + self::BASE)['valor']);
    }

    // ------------------------------------------------------------
    // tipo / modo
    // ------------------------------------------------------------

    public function testTipoAusenteOInvalidoEsRechazado(): void
    {
        $data = self::BASE;
        unset($data['tipo']);
        self::assertStringContainsString('programado, espontaneo', self::detalles($data)['tipo']);

        self::assertArrayHasKey('tipo', self::detalles(['tipo' => 'manual'] + self::BASE));
        self::assertArrayHasKey('tipo', self::detalles(['tipo' => 'PROGRAMADO'] + self::BASE));
    }

    public function testModoDebeSerMontoOPorcentaje(): void
    {
        $data = self::BASE;
        unset($data['modo']);
        self::assertSame("Debe ser 'monto' o 'porcentaje'", self::detalles($data)['modo']);

        self::assertArrayHasKey('modo', self::detalles(['modo' => 'ambos'] + self::BASE));
        self::assertArrayHasKey('modo', self::detalles(['modo' => 'Monto'] + self::BASE));
    }

    // ------------------------------------------------------------
    // valor
    // ------------------------------------------------------------

    public function testValorAusenteONoNumericoEsRechazado(): void
    {
        $data = self::BASE;
        unset($data['valor']);
        self::assertSame('Requerido y numérico', self::detalles($data)['valor']);

        self::assertSame('Requerido y numérico', self::detalles(['valor' => 'mucho'] + self::BASE)['valor']);
        self::assertSame('Requerido y numérico', self::detalles(['valor' => null] + self::BASE)['valor']);
        self::assertSame('Requerido y numérico', self::detalles(['valor' => ''] + self::BASE)['valor']);
    }

    public function testModoMontoExigeValorMayorACero(): void
    {
        self::assertSame('Para modo=monto debe ser > 0', self::detalles(['valor' => 0] + self::BASE)['valor']);
        self::assertSame('Para modo=monto debe ser > 0', self::detalles(['valor' => -100] + self::BASE)['valor']);
    }

    // ------------------------------------------------------------
    // fecha_aplicacion
    // ------------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function fechasInvalidas(): array
    {
        return [
            'ausente' => [null],
            'vacia' => [''],
            'formato argentino' => ['01/07/2026'],
            'con barras' => ['2026/07/01'],
            'con hora' => ['2026-07-01 00:00:00'],
            'anio fuera de rango' => ['2101-01-01'],
            // createFromFormat desborda estas en vez de fallar; el validador las detecta
            '31 de junio' => ['2026-06-31'],
            'mes 13' => ['2026-13-01'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function testFechaAplicacionDebeSerIso(mixed $fecha): void
    {
        $data = self::BASE;
        if ($fecha === null) {
            unset($data['fecha_aplicacion']);
        } else {
            $data['fecha_aplicacion'] = $fecha;
        }
        self::assertSame('Requerida (formato YYYY-MM-DD)', self::detalles($data)['fecha_aplicacion']);
    }

    public function testFechaAplicacionValidaSeDevuelveComoString(): void
    {
        self::assertSame('2027-01-15', ServicioAjusteValidator::validateCreate(['fecha_aplicacion' => '2027-01-15'] + self::BASE)['fecha_aplicacion']);
    }

    // ------------------------------------------------------------
    // cuota_desde_id
    // ------------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function cuotasDesdeInvalidas(): array
    {
        return [
            'cero' => [0],
            'negativo' => [-1],
            'texto' => ['primera'],
        ];
    }

    #[DataProvider('cuotasDesdeInvalidas')]
    public function testCuotaDesdeIdInvalidoEsRechazado(mixed $valor): void
    {
        self::assertSame('Debe ser entero positivo o null', self::detalles(['cuota_desde_id' => $valor] + self::BASE)['cuota_desde_id']);
    }

    public function testCuotaDesdeIdEsOpcionalYSeCasteaAEntero(): void
    {
        self::assertSame(42, ServicioAjusteValidator::validateCreate(['cuota_desde_id' => '42'] + self::BASE)['cuota_desde_id']);
        self::assertSame(7, ServicioAjusteValidator::validateCreate(['cuota_desde_id' => 7] + self::BASE)['cuota_desde_id']);
        self::assertNull(ServicioAjusteValidator::validateCreate(['cuota_desde_id' => ''] + self::BASE)['cuota_desde_id']);
        self::assertNull(ServicioAjusteValidator::validateCreate(['cuota_desde_id' => null] + self::BASE)['cuota_desde_id']);
    }

    // ------------------------------------------------------------
    // observaciones
    // ------------------------------------------------------------

    public function testObservacionesRechazaScripts(): void
    {
        self::assertSame('Contenido no permitido', self::detalles(['observaciones' => '<script>x</script>'] + self::BASE)['observaciones']);
        self::assertArrayHasKey('observaciones', self::detalles(['observaciones' => '< embed src=x>'] + self::BASE));
    }

    public function testObservacionesSeRecortanYVaciasPasanANull(): void
    {
        self::assertSame('Ajuste por inflación', ServicioAjusteValidator::validateCreate(['observaciones' => '  Ajuste por inflación  '] + self::BASE)['observaciones']);
        self::assertNull(ServicioAjusteValidator::validateCreate(['observaciones' => '   '] + self::BASE)['observaciones']);
    }

    // ------------------------------------------------------------
    // Whitelist / excepción
    // ------------------------------------------------------------

    public function testSoloDevuelveLasSeisClavesDelAjuste(): void
    {
        $clean = ServicioAjusteValidator::validateCreate([
            'servicio_id' => 1,
            'importe_anterior' => 100,
            'importe_nuevo' => 200,
            'created_by' => 1,
            'aplicado' => true,
        ] + self::BASE);

        self::assertSame(['tipo', 'modo', 'valor', 'fecha_aplicacion', 'cuota_desde_id', 'observaciones'], array_keys($clean));
    }

    public function testLanzaValidationExceptionCon422YTodosLosErrores(): void
    {
        try {
            ServicioAjusteValidator::validateCreate(['tipo' => 'x', 'modo' => 'y', 'valor' => 'z', 'cuota_desde_id' => -1]);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
            self::assertSame(['tipo', 'modo', 'valor', 'fecha_aplicacion', 'cuota_desde_id'], array_keys($e->getDetails()));
        }
    }
}
