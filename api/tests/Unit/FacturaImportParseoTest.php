<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Services\FacturaImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Parseo de celdas del import, sin archivo ni base. */
final class FacturaImportParseoTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cabeceras(): array
    {
        return [
            'export espanol' => ['Fecha factura', 'fecha_factura'],
            'con acento y mayusculas' => ['Fecha Envío', 'fecha_envio'],
            'numero con simbolo' => ['Nº', 'n'],
            'sin iva' => ['Sin IVA', 'sin_iva'],
            'snake case ya' => ['importe_con_iva', 'importe_con_iva'],
            'espacios de mas' => ['  Total   ARS ', 'total_ars'],
            'parentesis' => ['TDC (USD)', 'tdc_usd'],
        ];
    }

    #[DataProvider('cabeceras')]
    public function testNormalizaCabeceras(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, FacturaImportService::normalizarCabecera($entrada));
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function fechas(): array
    {
        return [
            'iso' => ['2025-03-05', '2025-03-05'],
            'iso con hora' => ['2025-03-05 10:30:00', '2025-03-05'],
            'argentina barras' => ['05/03/2025', '2025-03-05'],
            'argentina guiones' => ['5-3-2025', '2025-03-05'],
            'argentina puntos' => ['05.03.2025', '2025-03-05'],
            'anio corto' => ['05/03/25', '2025-03-05'],
            'serial excel' => [45721, '2025-03-05'],
            'serial excel float' => [45721.0, '2025-03-05'],
            'serial excel como string' => ['45721', '2025-03-05'],
            'datetime' => [new \DateTimeImmutable('2025-03-05'), '2025-03-05'],
            'vacio' => ['', null],
            'null' => [null, null],
            'basura queda igual para que el validador la rechace' => ['manana', 'manana'],
            'numero chico no es serial' => ['123', '123'],
        ];
    }

    #[DataProvider('fechas')]
    public function testParseaFechas(mixed $entrada, ?string $esperado): void
    {
        self::assertSame($esperado, FacturaImportService::parsearFecha($entrada));
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function numeros(): array
    {
        return [
            'float' => [1234.5, 1234.5],
            'int' => [1000, 1000.0],
            'punto decimal' => ['1234.56', 1234.56],
            'coma decimal' => ['1234,56', 1234.56],
            'miles con punto y coma decimal' => ['1.234.567,89', 1234567.89],
            'miles con coma y punto decimal' => ['1,234,567.89', 1234567.89],
            'con simbolo y espacios' => ['$ 1.234,50', 1234.5],
            'negativo' => ['-15,5', -15.5],
            'vacio' => ['', null],
            'null' => [null, null],
            'texto queda igual para que el validador lo rechace' => ['doce', 'doce'],
        ];
    }

    #[DataProvider('numeros')]
    public function testParseaNumeros(mixed $entrada, mixed $esperado): void
    {
        self::assertSame($esperado, FacturaImportService::parsearNumero($entrada));
    }

    public function testParseaBooleanos(): void
    {
        foreach (['Sí', 'si', 'SI', 's', 'yes', '1', 'true', 'x', 'X', 'cobrada', true] as $v) {
            self::assertTrue(FacturaImportService::parsearBool($v), var_export($v, true));
        }
        foreach (['No', 'n', '0', 'false', '', null, 'pendiente', false] as $v) {
            self::assertFalse(FacturaImportService::parsearBool($v), var_export($v, true));
        }
    }

    public function testParseaTiposDeFactura(): void
    {
        self::assertSame('A', FacturaImportService::parsearTipo(' a '));
        self::assertSame('CREDITO_MIPYME_A', FacturaImportService::parsearTipo('Credito MiPyME A'));
        self::assertSame('CREDITO_MIPYME_B', FacturaImportService::parsearTipo('CRÉDITO MIPYME B'));
        self::assertSame('NC_A', FacturaImportService::parsearTipo('nc-a'));
        self::assertSame('ND_E', FacturaImportService::parsearTipo('ND_E'));
    }

    public function testMapeaUnaFilaSegunElMapaDeColumnas(): void
    {
        $mapa = [0 => 'numero_factura', 1 => 'tipo', 2 => 'fecha_factura', 3 => 'importe_con_iva', 4 => 'cobrada', 5 => 'moneda', 7 => 'observaciones'];
        $celdas = [' 0001-00000001 ', 'credito mipyme a', '05/03/2025', '1.210,00', 'Sí', '', 'ignorada', 'obs'];

        $fila = FacturaImportService::mapearFila($celdas, $mapa);

        self::assertSame('0001-00000001', $fila['numero_factura']);
        self::assertSame('CREDITO_MIPYME_A', $fila['tipo']);
        self::assertSame('2025-03-05', $fila['fecha_factura']);
        self::assertSame(1210.0, $fila['importe_con_iva']);
        self::assertTrue($fila['cobrada']);
        self::assertSame('ARS', $fila['moneda'], 'moneda vacía = ARS');
        self::assertSame('obs', $fila['observaciones']);
        self::assertArrayNotHasKey('cuit', $fila, 'columna ausente no aparece');
    }

    public function testLaPlantillaTieneLasColumnasObligatorias(): void
    {
        $norm = array_map([FacturaImportService::class, 'normalizarCabecera'], FacturaImportService::PLANTILLA_CABECERAS);
        foreach (['numero', 'tipo', 'cuit', 'fecha_factura', 'sin_iva', 'con_iva'] as $c) {
            self::assertContains($c, $norm);
        }
    }
}
