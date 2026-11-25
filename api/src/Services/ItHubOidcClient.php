<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use ITHub\Api\Exceptions\AuthException;

/**
 * Cliente OpenID Connect del portal ITHub (sesión única de Intellihelp).
 *
 * Con ITHUB_CLIENT_ID e ITHUB_CLIENT_SECRET en el .env el ingreso a
 * Facturación pasa por ITHub: ITHub ya validó la cuenta de Google y el
 * dominio, decide si la persona tiene acceso a esta app y con qué rol (claim
 * `rol`) y vuelve al callback de la API con un código. La API canjea el
 * código acá (server-side, con el secreto y el verificador PKCE), valida el
 * id_token y sigue emitiendo SU access token y SU refresh como siempre.
 *
 * Validación de los JWT de ITHub (id_token y logout_token): RS256 con la
 * clave del `kid` publicada en /oauth/jwks (en caché una hora; si el kid no
 * está se vuelve a bajar, por rotación), emisor, destinatario = nuestro
 * client_id, vencimiento con 60 s de tolerancia y nonce. El logout_token
 * además tiene que traer el evento de back-channel logout y no traer nonce.
 *
 * Contrato completo: md/INTEGRAR-APLICACION.md del repo ithub-portal.
 *
 * No es final para poder reemplazar en tests los pedidos HTTP (pedir()).
 */
class ItHubOidcClient
{
    public const EVENTO_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';
    private const JWKS_TTL = 3600;
    private const TOLERANCIA = 60;

    /**
     * @param array{issuer?:string, client_id?:string, client_secret?:string, redirect_uri?:string} $cfg
     * @param string $cacheDir carpeta para la caché del JWKS (storage/cache)
     */
    public function __construct(private readonly array $cfg, private readonly string $cacheDir)
    {
    }

    /** ¿El ingreso es por ITHub? Hace falta client_id y client_secret. */
    public function habilitado(): bool
    {
        return $this->clientId() !== '' && (string) ($this->cfg['client_secret'] ?? '') !== '';
    }

    public function issuer(): string
    {
        return rtrim((string) ($this->cfg['issuer'] ?? 'https://ithub.intellihelp.tech'), '/');
    }

    public function clientId(): string
    {
        return (string) ($this->cfg['client_id'] ?? '');
    }

    public function redirectUri(): string
    {
        return (string) ($this->cfg['redirect_uri'] ?? '');
    }

    /** Base64 URL sin relleno (state, nonce, verificador y challenge de PKCE). */
    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Dirección de /oauth/authorize con PKCE S256. */
    public function urlAutorizacion(string $state, string $nonce, string $verificador): string
    {
        return $this->issuer() . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::b64url(hash('sha256', $verificador, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Canjea el código de autorización por los tokens (client_secret_basic +
     * verificador PKCE). Devuelve la respuesta de /oauth/token.
     *
     * @return array<string,mixed>
     */
    public function canjearCodigo(string $code, string $verificador): array
    {
        [$status, $r] = $this->pedir($this->issuer() . '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $verificador,
        ]);
        if ($status !== 200 || !is_string($r['id_token'] ?? null) || $r['id_token'] === '') {
            throw new AuthException('ITHub no aceptó el código de ingreso', 'ITHUB_CANJE');
        }
        return $r;
    }

    /**
     * Valida el id_token del canje y devuelve sus claims.
     *
     * @return array<string,mixed>
     */
    public function validarIdToken(string $jwt, string $nonce): array
    {
        $c = $this->validar($jwt);
        if ($nonce === '' || !hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
            throw new AuthException('Token de ITHub inválido', 'ITHUB_TOKEN_INVALIDO');
        }
        return $c;
    }

    /**
     * Valida el logout_token del aviso de cierre (back-channel logout).
     *
     * @return array<string,mixed>
     */
    public function validarLogoutToken(string $jwt): array
    {
        $c = $this->validar($jwt);
        $eventos = $c['events'] ?? null;
        if (!is_array($eventos) || !array_key_exists(self::EVENTO_LOGOUT, $eventos)
            || array_key_exists('nonce', $c)
            || (($c['sid'] ?? '') === '' && ($c['sub'] ?? '') === '')
        ) {
            throw new AuthException('Aviso de cierre inválido', 'ITHUB_TOKEN_INVALIDO');
        }
        return $c;
    }

    /**
     * Dirección de salida de ITHub: cierra la sesión única (en todas las apps)
     * y vuelve a la pantalla de ingreso. Con el id_token como pista ITHub no
     * pide confirmación.
     */
    public function urlSalida(?string $idToken, string $volverA): string
    {
        $q = ['client_id' => $this->clientId(), 'post_logout_redirect_uri' => $volverA];
        if ($idToken !== null && $idToken !== '') {
            $q['id_token_hint'] = $idToken;
        }
        return $this->issuer() . '/oauth/logout?' . http_build_query($q);
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * Firma (RS256 con la clave del kid), vencimiento, emisor y destinatario.
     *
     * @return array<string,mixed>
     */
    private function validar(string $jwt): array
    {
        $partes = explode('.', $jwt);
        $cab = count($partes) === 3 ? json_decode(JWT::urlsafeB64Decode($partes[0]), true) : null;
        if (!is_array($cab) || ($cab['alg'] ?? '') !== 'RS256' || !is_string($cab['kid'] ?? null)) {
            throw new AuthException('Token de ITHub inválido', 'ITHUB_TOKEN_INVALIDO');
        }

        $claves = $this->claves(false);
        if (!isset($claves[$cab['kid']])) {
            $claves = $this->claves(true); // ITHub rotó la clave
        }

        $leewayAnterior = JWT::$leeway;
        JWT::$leeway = self::TOLERANCIA;
        try {
            $keys = JWK::parseKeySet(['keys' => array_values($claves)], 'RS256');
            $c = json_decode((string) json_encode(JWT::decode($jwt, $keys)), true);
        } catch (\Throwable) {
            throw new AuthException('Token de ITHub inválido', 'ITHUB_TOKEN_INVALIDO');
        } finally {
            JWT::$leeway = $leewayAnterior;
        }

        $aud = $c['aud'] ?? '';
        $audOk = is_array($aud) ? in_array($this->clientId(), $aud, true) : $aud === $this->clientId();
        if (!is_array($c) || ($c['iss'] ?? '') !== $this->issuer() || !$audOk || !isset($c['exp'])) {
            throw new AuthException('Token de ITHub inválido', 'ITHUB_TOKEN_INVALIDO');
        }
        return $c;
    }

    /**
     * Claves públicas de ITHub por kid. Se guardan una hora en storage/cache;
     * con $forzar se vuelven a bajar aunque la caché esté vigente.
     *
     * @return array<string,array<string,mixed>> kid => JWK
     */
    private function claves(bool $forzar): array
    {
        $archivo = rtrim($this->cacheDir, '/\\') . '/ithub-jwks.json';
        if (!$forzar && is_file($archivo) && filemtime($archivo) > time() - self::JWKS_TTL) {
            $claves = json_decode((string) file_get_contents($archivo), true);
            if (is_array($claves) && $claves !== []) {
                return $claves;
            }
        }

        [$status, $r] = $this->pedir($this->issuer() . '/oauth/jwks');
        $claves = [];
        foreach ((array) ($status === 200 ? ($r['keys'] ?? []) : []) as $k) {
            if (is_array($k) && ($k['kty'] ?? '') === 'RSA' && isset($k['kid'], $k['n'], $k['e'])) {
                $claves[(string) $k['kid']] = $k;
            }
        }
        if ($claves !== []) {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0750, true);
            }
            @file_put_contents($archivo, json_encode($claves), LOCK_EX);
        }
        return $claves;
    }

    /**
     * Pedido HTTP a ITHub. Devuelve [status, cuerpo decodificado]; sin
     * conexión, [0, []]. Con $post autentica con client_secret_basic. El
     * secreto nunca va a un log.
     *
     * @param array<string,string>|null $post
     * @return array{0:int, 1:array<string,mixed>}
     */
    protected function pedir(string $url, ?array $post = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
            $opts[CURLOPT_USERPWD] = rawurlencode($this->clientId()) . ':'
                . rawurlencode((string) ($this->cfg['client_secret'] ?? ''));
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = is_string($res) ? json_decode($res, true) : null;
        return [$status, is_array($json) ? $json : []];
    }
}
