<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Endpoint público de health check.
 * Devuelve el estado de la API y la conectividad con la DB.
 * NO expone versiones ni info sensible.
 */
final class HealthController
{
    public function __construct(private readonly ContainerInterface $container)
    {
        // Boot de Eloquent (lazy)
        $this->container->get(Capsule::class);
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $dbOk = false;
        try {
            Capsule::connection()->getPdo()->query('SELECT 1');
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }

        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'db' => $dbOk ? 'up' : 'down',
            'time' => date('c'),
        ];

        return ResponseFactory::json($response, $payload, $dbOk ? 200 : 503);
    }
}
