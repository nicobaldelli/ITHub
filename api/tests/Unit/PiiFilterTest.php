<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Support\PiiFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PiiFilterTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function clavesSensibles(): array
    {
        return [
            'password' => ['password'],
            'password_hash' => ['password_hash'],
            'PASSWORD en mayusculas' => ['PASSWORD'],
            'token' => ['token'],
            'refresh_token' => ['refresh_token'],
            'access_token' => ['access_token'],
            'Authorization (header)' => ['Authorization'],
            'set-cookie' => ['set-cookie'],
            'api_key' => ['api_key'],
            'cbu' => ['cbu'],
            'jwt_secret (contiene secret)' => ['jwt_secret'],
            'smtp_pass (contiene pass)' => ['smtp_pass'],
            'gpg_passphrase' => ['gpg_passphrase'],
        ];
    }

    #[DataProvider('clavesSensibles')]
    public function testEnmascaraCompletamenteLasClavesSensibles(string $clave): void
    {
        $out = PiiFilter::filter([$clave => 'valor-secreto']);
        self::assertSame('***REDACTED***', $out[$clave]);
    }

    public function testEnmascaraClavesSensiblesEnArreglosAnidados(): void
    {
        $out = PiiFilter::filter([
            'request' => [
                'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
                'body' => ['email' => 'juan@empresa.com', 'password' => '123'],
            ],
        ]);

        self::assertSame('***REDACTED***', $out['request']['headers']['Authorization']);
        self::assertSame('application/json', $out['request']['headers']['Accept']);
        self::assertSame('***REDACTED***', $out['request']['body']['password']);
    }

    public function testEnmascaraEmailsDentroDeStringsDejandoPrimeraLetraYDominio(): void
    {
        $out = PiiFilter::filter(['msg' => 'login de juan.perez@empresa.com.ar fallido']);
        self::assertSame('login de j*********@empresa.com.ar fallido', $out['msg']);
    }

    public function testEnmascaraCuitDentroDeStrings(): void
    {
        $out = PiiFilter::filter(['msg' => 'cliente 20-12345678-6 creado']);
        self::assertSame('cliente 20-***-***-6 creado', $out['msg']);

        $sinGuiones = PiiFilter::filter(['msg' => 'cliente 20123456786 creado']);
        self::assertSame('cliente 20-***-***-6 creado', $sinGuiones['msg']);
    }

    public function testNoTocaValoresNoSensiblesNiTiposNoString(): void
    {
        $out = PiiFilter::filter([
            'user_id' => 42,
            'activo' => true,
            'nada' => null,
            'importe' => 12.5,
            'path' => '/api/v1/facturas',
        ]);

        self::assertSame(42, $out['user_id']);
        self::assertTrue($out['activo']);
        self::assertNull($out['nada']);
        self::assertSame(12.5, $out['importe']);
        self::assertSame('/api/v1/facturas', $out['path']);
    }

    public function testConservaLasClavesOriginales(): void
    {
        $out = PiiFilter::filter(['a' => 1, 'B' => 2, 3 => 'x']);
        self::assertSame(['a', 'B', 3], array_keys($out));
    }
}
