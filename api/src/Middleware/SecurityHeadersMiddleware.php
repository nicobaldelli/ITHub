<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Headers de seguridad aplicados a todas las respuestas.
 * Refuerza lo que ya hace .htaccess (defensa en profundidad).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $settings = $this->container->get('settings');
        $sec = $settings['security'];

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-site');

        // Para una API JSON-only el CSP estricto basta; el frontend tiene su propio CSP via meta.
        $response = $response->withHeader(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
        );

        // HSTS solo si HTTPS (en local podríamos no querer forzar)
        if ($sec['enable_hsts'] && $this->isHttps($request)) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                sprintf('max-age=%d; includeSubDomains; preload', $sec['hsts_max_age'])
            );
        }

        // Limpieza de headers que filtran info
        return $response
            ->withoutHeader('X-Powered-By')
            ->withoutHeader('Server');
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        $uri = $request->getUri();
        if ($uri->getScheme() === 'https') {
            return true;
        }
        // Detrás de un proxy/load balancer
        $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
        return strtolower($forwardedProto) === 'https';
    }
}
