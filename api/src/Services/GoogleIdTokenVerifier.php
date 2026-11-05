<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Google\Client as GoogleClient;
use ITHub\Api\Exceptions\AuthException;

/**
 * Verifica un ID token de Google Identity Services ("Sign in with Google").
 *
 * google/apiclient descarga las claves públicas de Google (con cache) y
 * valida firma, expiración e `aud` (que el token haya sido emitido para
 * NUESTRO client id). Lo que el token diga de dominio y email verificado
 * lo decide AuthService; acá solo se normaliza el payload.
 *
 * No es final para poder reemplazarla en tests por una versión que no
 * llama a Google.
 */
class GoogleIdTokenVerifier
{
    public function __construct(private readonly string $clientId)
    {
    }

    /**
     * @return array{sub:string, email:string, email_verified:bool, hd:string, name:string}
     */
    public function verificar(string $idToken): array
    {
        if ($this->clientId === '') {
            throw new AuthException('Login con Google no habilitado', 'GOOGLE_LOGIN_DISABLED');
        }

        $client = new GoogleClient();
        $client->setClientId($this->clientId);

        try {
            $payload = $client->verifyIdToken($idToken);
        } catch (\Throwable) {
            $payload = false;
        }
        if (!is_array($payload)) {
            throw new AuthException('Token de Google inválido o vencido', 'GOOGLE_TOKEN_INVALID');
        }

        return [
            'sub' => (string) ($payload['sub'] ?? ''),
            'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
            'email_verified' => filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hd' => strtolower((string) ($payload['hd'] ?? '')),
            'name' => (string) ($payload['name'] ?? ''),
        ];
    }
}
