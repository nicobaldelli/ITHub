<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Support;

use Firebase\JWT\JWT;
use ITHub\Api\Services\ItHubOidcClient;

/**
 * ITHub de mentira para los tests: genera un par de claves RSA en el momento,
 * firma id_token y logout_token como lo hace ITHub y arma un ItHubOidcClient
 * cuyos pedidos HTTP (JWKS y canje del código) no salen de la máquina.
 */
final class ItHubFalso
{
    public const ISSUER = 'https://ithub.test';
    public const CLIENT_ID = 'facturacion';

    /** @var array<string,string> kid => PEM privado */
    private array $privadas = [];
    /** @var array<string,array<string,string>> kid => JWK público */
    private array $publicas = [];

    /** Cuántas veces se bajó el JWKS (para probar la caché y la rotación). */
    public int $bajadasJwks = 0;

    /** Respuesta del canje de /oauth/token: [status, cuerpo]. */
    /** @var array{0:int,1:array<string,mixed>} */
    public array $respuestaToken = [400, ['error' => 'invalid_grant']];

    /** Último pedido al token endpoint (para verificar PKCE). */
    /** @var array<string,string>|null */
    public ?array $ultimoCanje = null;

    public function __construct(public readonly string $cacheDir)
    {
        $this->nuevaClave('k1');
    }

    /** Agrega (o rota a) una clave nueva con ese kid. */
    public function nuevaClave(string $kid): void
    {
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // PHP de Windows no encuentra solo el openssl.cnf
        $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && is_file($cnf)) {
            $opts['config'] = $cnf;
        }
        $key = openssl_pkey_new($opts);
        if ($key === false) {
            throw new \RuntimeException('No se pudo generar la clave RSA de prueba');
        }
        openssl_pkey_export($key, $pem, null, $opts);
        $det = openssl_pkey_get_details($key);
        $this->privadas[$kid] = $pem;
        $this->publicas[$kid] = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => ItHubOidcClient::b64url($det['rsa']['n']),
            'e' => ItHubOidcClient::b64url($det['rsa']['e']),
        ];
    }

    /**
     * id_token como el de ITHub (claims sobreescribibles).
     *
     * @param array<string,mixed> $claims
     */
    public function idToken(array $claims = [], string $kid = 'k1'): string
    {
        $ahora = time();
        return JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => '12',
            'sid' => 'sid-abc',
            'email' => 'juan@intellihelp.tech',
            'email_verified' => true,
            'name' => 'Juan Gómez',
            'hd' => 'intellihelp.tech',
            'rol' => 'ventas',
            'roles' => ['ventas'],
            'nonce' => 'nonce-1',
            'iat' => $ahora,
            'exp' => $ahora + 300,
            'auth_time' => $ahora,
        ], $claims), $this->privadas[$kid], 'RS256', $kid);
    }

    /** @param array<string,mixed> $claims */
    public function logoutToken(array $claims = [], string $kid = 'k1'): string
    {
        $ahora = time();
        return JWT::encode(array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => '12',
            'sid' => 'sid-abc',
            'iat' => $ahora,
            'exp' => $ahora + 120,
            'jti' => bin2hex(random_bytes(8)),
            'events' => [ItHubOidcClient::EVENTO_LOGOUT => new \stdClass()],
        ], $claims), $this->privadas[$kid], 'RS256', $kid);
    }

    /** Cliente con la config de prueba que habla con este ITHub falso. */
    public function cliente(string $secreto = 'ith_secreto_de_prueba'): ItHubOidcClient
    {
        $falso = $this;
        return new class ([
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => $secreto,
            'redirect_uri' => 'https://api.test/api/v1/auth/ithub/callback',
        ], $this->cacheDir, $falso) extends ItHubOidcClient {
            /** @param array<string,string> $cfg */
            public function __construct(array $cfg, string $cacheDir, private readonly ItHubFalso $falso)
            {
                parent::__construct($cfg, $cacheDir);
            }

            protected function pedir(string $url, ?array $post = null): array
            {
                if (str_ends_with($url, '/oauth/jwks')) {
                    $this->falso->bajadasJwks++;
                    return [200, ['keys' => $this->falso->jwks()]];
                }
                if (str_ends_with($url, '/oauth/token')) {
                    $this->falso->ultimoCanje = $post;
                    return $this->falso->respuestaToken;
                }
                return [404, []];
            }
        };
    }

    /** @return list<array<string,string>> */
    public function jwks(): array
    {
        return array_values($this->publicas);
    }

    /** Carpeta temporal nueva para la caché del JWKS. */
    public static function carpetaTemporal(): string
    {
        $dir = sys_get_temp_dir() . '/ithub-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        return $dir;
    }

    public static function borrarCarpeta(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
