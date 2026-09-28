<?php

declare(strict_types=1);

namespace ITHub\Api\Middleware;

use ITHub\Api\Exceptions\AppException;
use ITHub\Api\Exceptions\RateLimitException;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Manejo global de errores.
 * - En producción NUNCA expone stack traces.
 * - Devuelve siempre el mismo shape JSON con request_id para correlacionar logs.
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            return $this->renderError($request, $e);
        }
    }

    private function renderError(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        $requestId = $request->getAttribute('request_id', 'unknown');
        $settings = $this->container->get('settings');
        $isProd = (bool) ($settings['app']['is_production'] ?? true);

        // Excepciones controladas
        if ($e instanceof AppException) {
            // Log de seguridad para 401/403/429
            if (in_array($e->getStatusCode(), [401, 403, 429], true)) {
                $this->logger->warning('Acceso denegado', [
                    'code' => $e->getErrorCode(),
                    'status' => $e->getStatusCode(),
                    'request_id' => $requestId,
                    'path' => $request->getUri()->getPath(),
                    'ip' => $this->clientIp($request),
                ]);
            }

            $response = $this->responseFactory->createResponse();
            $response = ResponseFactory::error(
                $response,
                $e->getStatusCode(),
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getDetails(),
                $requestId
            );

            if ($e instanceof RateLimitException) {
                $response = $response->withHeader('Retry-After', (string) $e->retryAfter);
            }

            return $response;
        }

        // Excepciones HTTP de Slim (404 de ruta, 405 de método): status real,
        // sin loguearlas como error 500
        if ($e instanceof HttpException) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            [$code, $message] = match (true) {
                $e instanceof HttpNotFoundException => ['NOT_FOUND', 'Ruta no encontrada'],
                $e instanceof HttpMethodNotAllowedException => ['METHOD_NOT_ALLOWED', 'Método no permitido para esta ruta'],
                default => ['HTTP_ERROR', $e->getTitle() !== '' ? $e->getTitle() : 'Error HTTP'],
            };

            $response = $this->responseFactory->createResponse();
            $response = ResponseFactory::error($response, $status, $code, $message, [], $requestId);

            if ($e instanceof HttpMethodNotAllowedException) {
                $response = $response->withHeader('Allow', implode(', ', $e->getAllowedMethods()));
            }

            return $response;
        }

        // Errores inesperados: log con stack trace, respuesta genérica
        $this->logger->error('Excepción no controlada', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'request_id' => $requestId,
            'path' => $request->getUri()->getPath(),
        ]);

        $message = $isProd
            ? 'Ocurrió un error procesando la solicitud. Contactá al administrador con el request_id.'
            : $e->getMessage();

        $details = $isProd ? [] : ['exception' => get_class($e), 'file' => $e->getFile() . ':' . $e->getLine()];

        $response = $this->responseFactory->createResponse();
        return ResponseFactory::error($response, 500, 'INTERNAL_ERROR', $message, $details, $requestId);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        // Solo REMOTE_ADDR (X-Forwarded-For es controlable por el cliente)
        $server = $request->getServerParams();
        return $server['REMOTE_ADDR'] ?? 'unknown';
    }
}
