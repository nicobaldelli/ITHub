<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use Firebase\JWT\JWT;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Services\ItHubOidcClient;
use ITHub\Api\Tests\Support\ItHubFalso;
use PHPUnit\Framework\TestCase;

/**
 * Validación de los JWT de ITHub (id_token y logout_token), PKCE y caché de
 * claves. Las claves se generan en el momento: no se habla con ITHub.
 */
final class ItHubOidcClientTest extends TestCase
{
    private ItHubFalso $ithub;
    private ItHubOidcClient $cliente;

    protected function setUp(): void
    {
        $this->ithub = new ItHubFalso(ItHubFalso::carpetaTemporal());
        $this->cliente = $this->ithub->cliente();
    }

    protected function tearDown(): void
    {
        ItHubFalso::borrarCarpeta($this->ithub->cacheDir);
    }

    private function rechazado(callable $fn): string
    {
        try {
            $fn();
        } catch (AuthException $e) {
            return $e->getErrorCode();
        }
        self::fail('Se esperaba AuthException');
    }

    public function testHabilitadoSoloConClientIdYSecreto(): void
    {
        self::assertTrue($this->cliente->habilitado());
        self::assertFalse((new ItHubOidcClient(['client_id' => 'facturacion'], $this->ithub->cacheDir))->habilitado());
        self::assertFalse((new ItHubOidcClient(['client_secret' => 'x'], $this->ithub->cacheDir))->habilitado());
        self::assertFalse((new ItHubOidcClient([], $this->ithub->cacheDir))->habilitado());
    }

    public function testUrlDeAutorizacionConPkceS256(): void
    {
        $url = $this->cliente->urlAutorizacion('st', 'no', 'verificador-largo');
        self::assertStringStartsWith(ItHubFalso::ISSUER . '/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('facturacion', $q['client_id']);
        self::assertSame('code', $q['response_type']);
        self::assertSame('openid email profile', $q['scope']);
        self::assertSame('st', $q['state']);
        self::assertSame('no', $q['nonce']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame(ItHubOidcClient::b64url(hash('sha256', 'verificador-largo', true)), $q['code_challenge']);
        self::assertSame('https://api.test/api/v1/auth/ithub/callback', $q['redirect_uri']);
    }

    public function testCanjeMandaElVerificadorYExigeIdToken(): void
    {
        $this->ithub->respuestaToken = [200, ['id_token' => 'x.y.z', 'token_type' => 'Bearer']];
        $r = $this->cliente->canjearCodigo('codigo', 'ver');
        self::assertSame('x.y.z', $r['id_token']);
        self::assertSame('authorization_code', $this->ithub->ultimoCanje['grant_type']);
        self::assertSame('ver', $this->ithub->ultimoCanje['code_verifier']);
        self::assertSame('codigo', $this->ithub->ultimoCanje['code']);

        $this->ithub->respuestaToken = [400, ['error' => 'invalid_grant']];
        self::assertSame('ITHUB_CANJE', $this->rechazado(fn () => $this->cliente->canjearCodigo('c', 'v')));
        $this->ithub->respuestaToken = [200, ['access_token' => 'sin-id-token']];
        self::assertSame('ITHUB_CANJE', $this->rechazado(fn () => $this->cliente->canjearCodigo('c', 'v')));
    }

    public function testIdTokenValido(): void
    {
        $c = $this->cliente->validarIdToken($this->ithub->idToken(), 'nonce-1');
        self::assertSame('juan@intellihelp.tech', $c['email']);
        self::assertSame('ventas', $c['rol']);
        self::assertSame('sid-abc', $c['sid']);
    }

    public function testIdTokenRechazadoPorClaims(): void
    {
        $casos = [
            'nonce distinto' => [[], 'otro-nonce'],
            'nonce vacio' => [[], ''],
            'otro emisor' => [['iss' => 'https://malo.test'], 'nonce-1'],
            'otra app' => [['aud' => 'crm'], 'nonce-1'],
            'vencido' => [['exp' => time() - 120], 'nonce-1'],
            'futuro' => [['iat' => time() + 600, 'nbf' => time() + 600], 'nonce-1'],
        ];
        foreach ($casos as $nombre => [$claims, $nonce]) {
            $token = $this->ithub->idToken($claims);
            self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(
                fn () => $this->cliente->validarIdToken($token, $nonce)
            ), $nombre);
        }
    }

    public function testAudienciaComoListaQueNosIncluye(): void
    {
        $c = $this->cliente->validarIdToken($this->ithub->idToken(['aud' => ['otra', 'facturacion']]), 'nonce-1');
        self::assertSame('12', $c['sub']);
    }

    public function testFirmaDeOtraClaveRechazada(): void
    {
        $otro = new ItHubFalso($this->ithub->cacheDir . '-otro');
        $token = $otro->idToken(); // mismo kid "k1", otra clave privada
        self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(fn () => $this->cliente->validarIdToken($token, 'nonce-1')));
    }

    public function testTokenAlteradoOSinFirmaRechazado(): void
    {
        [$h, $p, $s] = explode('.', $this->ithub->idToken());
        $payload = json_decode(JWT::urlsafeB64Decode($p), true);
        $payload['rol'] = 'admin';
        $alterado = $h . '.' . JWT::urlsafeB64Encode((string) json_encode($payload)) . '.' . $s;
        self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(fn () => $this->cliente->validarIdToken($alterado, 'nonce-1')));

        $none = JWT::urlsafeB64Encode('{"alg":"none","kid":"k1"}') . '.' . $p . '.';
        self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(fn () => $this->cliente->validarIdToken($none, 'nonce-1')));

        // HS256 firmado con la clave pública como secreto (confusión de algoritmo)
        $hs = JWT::encode($payload, 'cualquier-secreto-de-mas-de-32-bytes-xxxxxxxx', 'HS256', 'k1');
        self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(fn () => $this->cliente->validarIdToken($hs, 'nonce-1')));

        self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(fn () => $this->cliente->validarIdToken('basura', 'nonce-1')));
    }

    public function testClavesEnCacheYRotacion(): void
    {
        $this->cliente->validarIdToken($this->ithub->idToken(), 'nonce-1');
        $this->cliente->validarIdToken($this->ithub->idToken(), 'nonce-1');
        self::assertSame(1, $this->ithub->bajadasJwks, 'la segunda validación usa la caché');

        // ITHub rota la clave: kid nuevo que no está en la caché → se vuelve a bajar
        $this->ithub->nuevaClave('k2');
        $c = $this->cliente->validarIdToken($this->ithub->idToken([], 'k2'), 'nonce-1');
        self::assertSame('12', $c['sub']);
        self::assertSame(2, $this->ithub->bajadasJwks);
    }

    public function testLogoutToken(): void
    {
        $c = $this->cliente->validarLogoutToken($this->ithub->logoutToken());
        self::assertSame('sid-abc', $c['sid']);

        $casos = [
            'sin evento' => ['events' => ['otro' => new \stdClass()]],
            'con nonce' => ['nonce' => 'x'],
            'sin sid ni sub' => ['sid' => '', 'sub' => ''],
            'otra app' => ['aud' => 'crm'],
            'vencido' => ['exp' => time() - 300],
        ];
        foreach ($casos as $nombre => $claims) {
            $token = $this->ithub->logoutToken($claims);
            self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(
                fn () => $this->cliente->validarLogoutToken($token)
            ), $nombre);
        }
        // Un id_token no sirve como aviso de cierre
        $id = $this->ithub->idToken();
        self::assertSame('ITHUB_TOKEN_INVALIDO', $this->rechazado(fn () => $this->cliente->validarLogoutToken($id)));
    }

    public function testUrlDeSalida(): void
    {
        $url = $this->cliente->urlSalida('el.id.token', 'https://web.test/login/');
        self::assertStringStartsWith(ItHubFalso::ISSUER . '/oauth/logout?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('el.id.token', $q['id_token_hint']);
        self::assertSame('https://web.test/login/', $q['post_logout_redirect_uri']);
        self::assertSame('facturacion', $q['client_id']);

        parse_str((string) parse_url($this->cliente->urlSalida(null, 'https://web.test/login/'), PHP_URL_QUERY), $q);
        self::assertArrayNotHasKey('id_token_hint', $q);
    }
}
