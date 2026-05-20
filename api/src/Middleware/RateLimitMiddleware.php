<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use ITHub\Api\Exceptions\RateLimitException;
use ITHub\Api\Support\ContainerProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Rate limiting basado en sliding window con almacenamiento en filesystem.
 *
 * Para producción a mayor escala se puede swappear el adapter por Redis/Memcached
 * (FilesystemAdapter es seguro y suficiente para volumen de Hostinger compartido).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const PROFILES_MAP = [
        'login' => 'login_per_email',
        'refresh' => 'refresh_per_ip',
        'change_password' => 'change_password',
        'reset_password' => 'reset_password',
        'import' => 'import',
        'general' => 'general_per_user',
    ];

    public function __construct(
        private readonly string $profile = 'general'
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var ContainerInterface $container */
        $container = $this->resolveContainer($request);
        $settings = $container->get('settings');

        $profileKey = self::PROFILES_MAP[$this->profile] ?? 'general_per_user';
        [$limit, $window] = $settings['ratelimit'][$profileKey] ?? [60, 60];

        $basePath = $container->get('basePath');
        $cacheDir = $basePath . '/' . $settings['storage']['ratelimit'];
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }

        $cache = new FilesystemAdapter('rl', 0, $cacheDir);

        $keys = $this->buildKeys($this->profile, $request);

        foreach ($keys as $key) {
            // PSR-6 no permite ':' en cache keys → reemplazamos por '.'
            $safeKey = strtr($key, [':' => '.', '/' => '.', '\\' => '.', '{' => '_', '}' => '_', '(' => '_', ')' => '_', '@' => '.']);
            $item = $cache->getItem($safeKey);
            $data = $item->get() ?? ['count' => 0, 'reset_at' => time() + $window];

            // Reiniciar ventana si expiró
            if ($data['reset_at'] < time()) {
                $data = ['count' => 0, 'reset_at' => time() + $window];
            }

            $data['count']++;

            if ($data['count'] > $limit) {
                $retryAfter = max(1, $data['reset_at'] - time());
                throw new RateLimitException($retryAfter);
            }

            $item->set($data);
            $item->expiresAfter($window);
            $cache->save($item);
        }

        return $handler->handle($request);
    }

    /**
     * @return string[]
     */
    private function buildKeys(string $profile, ServerRequestInterface $request): array
    {
        $ip = $this->clientIp($request);
        $user = $request->getAttribute('user');
        $userId = is_object($user) && property_exists($user, 'id') ? (string) $user->id : 'anon';

        return match ($profile) {
            'login' => array_filter([
                $this->emailKey($request),
                "login:ip:{$ip}",
            ]),
            'refresh' => ["refresh:ip:{$ip}"],
            'change_password' => ["chpwd:user:{$userId}"],
            'reset_password' => ["reset:user:{$userId}"],
            'import' => ["import:user:{$userId}"],
            default => [
                $userId !== 'anon' ? "gen:user:{$userId}" : "gen:ip:{$ip}",
            ],
        };
    }

    private function emailKey(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['email']) && is_string($body['email'])) {
            $email = strtolower(trim($body['email']));
            return 'login:email:' . hash('sha256', $email);
        }
        return null;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        return $server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function resolveContainer(ServerRequestInterface $request): ContainerInterface
    {
        return ContainerProvider::get();
    }
}
