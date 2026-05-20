<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS estricto: solo orígenes en whitelist, con credenciales habilitadas para las cookies de refresh.
 * Responde a preflight OPTIONS con headers apropiados.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ResponseFactoryInterface $responseFactory
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $settings = $this->container->get('settings');
        $cors = $settings['cors'];

        $origin = $request->getHeaderLine('Origin');
        $allowed = in_array($origin, $cors['allowed_origins'], true);

        // Preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = $this->responseFactory->createResponse(204);
            if ($allowed) {
                $response = $this->applyHeaders($response, $origin, $cors);
            }
            return $response;
        }

        $response = $handler->handle($request);

        if ($allowed) {
            $response = $this->applyHeaders($response, $origin, $cors);
        }

        return $response->withHeader('Vary', 'Origin');
    }

    private function applyHeaders(ResponseInterface $response, string $origin, array $cors): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $cors['allowed_methods']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $cors['allowed_headers']))
            ->withHeader('Access-Control-Max-Age', (string) $cors['max_age']);

        if (!empty($cors['exposed_headers'])) {
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                implode(', ', $cors['exposed_headers'])
            );
        }

        if ($cors['allow_credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
