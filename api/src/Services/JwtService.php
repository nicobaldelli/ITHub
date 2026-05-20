<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Firebase\JWT\JWT;
use ITHub\Api\Models\User;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Genera y verifica JWTs (access tokens) y refresh tokens.
 *
 * - Access: JWT HS256 con TTL corto (15 min por default).
 * - Refresh: token opaco de 256 bits, persistido en DB como SHA-256.
 *
 * La verificación del access se hace en JwtAuthMiddleware (con firebase/php-jwt).
 * Acá solo se emiten.
 */
final class JwtService
{
    /** @var array<string,mixed> */
    private readonly array $jwtCfg;

    public function __construct(private readonly ContainerInterface $container)
    {
        $settings = $container->get('settings');
        $this->jwtCfg = $settings['jwt'];
    }

    /**
     * @return array{token: string, expires_at: int, jti: string}
     */
    public function issueAccessToken(User $user): array
    {
        $now = time();
        $ttl = $this->jwtCfg['access_ttl'];
        $jti = Uuid::uuid4()->toString();

        $payload = [
            'iss' => $this->jwtCfg['issuer'],
            'aud' => $this->jwtCfg['audience'],
            'sub' => (string) $user->id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
            'rol' => $user->rol,
        ];

        $token = JWT::encode($payload, $this->jwtCfg['secret'], $this->jwtCfg['algo']);

        return [
            'token' => $token,
            'expires_at' => $now + $ttl,
            'jti' => $jti,
        ];
    }

    /**
     * Genera un refresh token opaco (no firmado, no parseable).
     * Devuelve el valor en claro (que se manda al cliente) y su hash (que se guarda).
     *
     * @return array{token: string, hash: string, expires_at: int}
     */
    public function generateRefreshToken(): array
    {
        $raw = bin2hex(random_bytes(32)); // 64 chars hex = 256 bits
        $hash = hash('sha256', $raw);
        $expiresAt = time() + $this->jwtCfg['refresh_ttl'];

        return [
            'token' => $raw,
            'hash' => $hash,
            'expires_at' => $expiresAt,
        ];
    }

    public function hashRefreshToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public function getAccessTtl(): int
    {
        return $this->jwtCfg['access_ttl'];
    }

    public function getRefreshTtl(): int
    {
        return $this->jwtCfg['refresh_ttl'];
    }
}
