<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Services\DashboardService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DashboardController
{
    private readonly DashboardService $service;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->service = $this->container->get(DashboardService::class);
    }

    public function kpis(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json($response, $this->service->kpis($request->getQueryParams()));
    }

    public function tendencias(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $meses = isset($params['meses']) ? (int) $params['meses'] : 12;
        return ResponseFactory::json($response, $this->service->tendencias($meses, $params));
    }

    public function aging(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json($response, $this->service->aging($request->getQueryParams()));
    }

    public function topClientes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? (int) $params['limit'] : 10;
        return ResponseFactory::json($response, $this->service->topClientes($limit, $params));
    }

    public function distribucionTipo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json($response, $this->service->distribucionTipo($request->getQueryParams()));
    }

    public function distribucionMoneda(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return ResponseFactory::json($response, $this->service->distribucionMoneda($request->getQueryParams()));
    }
}
