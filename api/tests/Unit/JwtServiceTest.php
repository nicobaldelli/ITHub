<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use ITHub\Api\Models\User;
use ITHub\Api\Services\JwtService;
use ITHub\Api\Tests\Support\ArrayContainer;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    private const SECRET = 'un-secreto-de-prueba-suficientemente-largo-1234567890';

    /** @return array<string,mixed> */
    private function jwtCfg(int $accessTtl = 900, int $refreshTtl = 604800): array
    {
        return [
            'secret' => self::SECRET,
            'algo' => 'HS256',
            'issuer' => 'http://api.test',
            'audience' => 'http://web.test',
            'access_ttl' => $accessTtl,
            'refresh_ttl' => $refreshTtl,
        ];
    }

    private function service(int $accessTtl = 900): JwtService
    {
        return new JwtService(new ArrayContainer(['settings' => ['jwt' => $this->jwtCfg($accessTtl)]]));
    }

    private function usuario(int $id = 7, string $rol = 'ventas'): User
    {
        $u = new User();
        $u->id = $id;
        $u->rol = $rol;
        return $u;
    }

    public function testElAccessTokenLlevaLosClaimsEsperadosYSeVerificaConElSecret(): void
    {
        $svc = $this->service(900);
        $antes = time();
        $res = $svc->issueAccessToken($this->usuario(42, 'admin'));

        self::assertArrayHasKey('token', $res);
        self::assertArrayHasKey('expires_at', $res);
        self::assertArrayHasKey('jti', $res);

        $claims = JWT::decode($res['token'], new Key(self::SECRET, 'HS256'));

        self::assertSame('http://api.test', $claims->iss);
        self::assertSame('http://web.test', $claims->aud);
        self::assertSame('42', $claims->sub, 'sub viaja como string');
        self::assertSame('admin', $claims->rol);
        self::assertSame($res['jti'], $claims->jti);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $claims->jti,
            'jti es un UUID v4',
        );
        self::assertSame($claims->iat + 900, $claims->exp, 'exp = iat + access_ttl');
        self::assertSame($claims->exp, $res['expires_at']);
        self::assertGreaterThanOrEqual($antes, $claims->iat);
        self::assertSame($claims->iat, $claims->nbf);
    }

    public function testElAccessTokenNoSeVerificaConOtroSecret(): void
    {
        $res = $this->service()->issueAccessToken($this->usuario());

        $this->expectException(SignatureInvalidException::class);
        JWT::decode($res['token'], new Key('otro-secret-distinto-al-de-firma-000000000', 'HS256'));
    }

    public function testCadaAccessTokenTieneUnJtiDistinto(): void
    {
        $svc = $this->service();
        $u = $this->usuario();
        $a = $svc->issueAccessToken($u);
        $b = $svc->issueAccessToken($u);

        self::assertNotSame($a['jti'], $b['jti']);
        self::assertNotSame($a['token'], $b['token']);
    }

    public function testElRefreshTokenEsOpacoDe256BitsYSuHashEsSha256(): void
    {
        $svc = $this->service();
        $res = $svc->generateRefreshToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $res['token'], '32 bytes en hex');
        self::assertSame(hash('sha256', $res['token']), $res['hash']);
        self::assertSame($res['hash'], $svc->hashRefreshToken($res['token']));
        self::assertEqualsWithDelta(time() + 604800, $res['expires_at'], 2);
    }

    public function testDosRefreshTokensNuncaCoinciden(): void
    {
        $svc = $this->service();
        self::assertNotSame($svc->generateRefreshToken()['token'], $svc->generateRefreshToken()['token']);
    }

    public function testExponeLosTtlConfigurados(): void
    {
        $svc = new JwtService(new ArrayContainer(['settings' => ['jwt' => $this->jwtCfg(123, 456)]]));
        self::assertSame(123, $svc->getAccessTtl());
        self::assertSame(456, $svc->getRefreshTtl());
    }
}
