<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Helpers\CuitValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CuitValidatorTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function cuitsValidos(): array
    {
        return [
            'con guiones' => ['20-12345678-6'],
            'sin guiones' => ['20123456786'],
            'con espacios y guiones' => [' 20-12345678-6 '],
            'persona juridica 30' => ['30-70826853-0'],
            'digito verificador 0 (11 - mod = 11)' => ['20-00000006-0'],
            'digito verificador 1' => ['20-00000000-1'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function cuitsInvalidos(): array
    {
        return [
            'checksum incorrecto' => ['20-12345678-9'],
            'muy corto' => ['20-1234567-6'],
            'muy largo' => ['20-123456789-6'],
            'vacio' => [''],
            'letras' => ['2A-12345678-6'],
            'solo guiones' => ['--'],
        ];
    }

    #[DataProvider('cuitsValidos')]
    public function testAceptaCuitsConChecksumCorrecto(string $cuit): void
    {
        self::assertTrue(CuitValidator::isValid($cuit), "Debería ser válido: {$cuit}");
    }

    #[DataProvider('cuitsInvalidos')]
    public function testRechazaCuitsInvalidos(string $cuit): void
    {
        self::assertFalse(CuitValidator::isValid($cuit), "Debería ser inválido: {$cuit}");
    }

    public function testRechazaCuandoElResultadoDelChecksumEsDiez(): void
    {
        // 20-00000001: suma = 2*5 + 1*2 = 12; 12 % 11 = 1; 11 - 1 = 10 -> no existe
        // DV válido para ese cuerpo, se rechaza con cualquier dígito.
        foreach (range(0, 9) as $dv) {
            self::assertFalse(CuitValidator::isValid("20-00000001-{$dv}"));
        }
    }

    public function testNormalizeDevuelveFormatoCanonico(): void
    {
        self::assertSame('20-12345678-6', CuitValidator::normalize('20123456786'));
        self::assertSame('20-12345678-6', CuitValidator::normalize('20-12345678-6'));
        self::assertSame('20-12345678-6', CuitValidator::normalize(' 20 12345678 6 '));
    }

    public function testNormalizeDevuelveElInputSiNoTieneOnceDigitos(): void
    {
        self::assertSame('123', CuitValidator::normalize('123'));
        self::assertSame('', CuitValidator::normalize(''));
    }
}
