<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\ClienteRepository;
use ITHub\Api\Services\ClienteService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ClientesController
{
    private readonly ClienteRepository $repo;
    private readonly ClienteService $service;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->repo = $this->container->get(ClienteRepository::class);
        $this->service = $this->container->get(ClienteService::class);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 25)));

        $paginator = $this->repo->paginate($params, $page, $perPage);

        return ResponseFactory::json($response, $paginator->items(), 200, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $cliente = $this->repo->findById((int) $args['id']);
        if ($cliente === null) {
            throw new NotFoundException('Cliente no encontrado');
        }
        return ResponseFactory::json($response, $cliente);
    }

    public function facturas(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $cliente = $this->repo->findById((int) $args['id']);
        if ($cliente === null) {
            throw new NotFoundException('Cliente no encontrado');
        }
        // Devuelve solo los IDs/resumen para evitar payloads enormes.
        $facturas = $cliente->facturas()
            ->select('id', 'numero_factura', 'tipo', 'moneda', 'fecha_factura', 'vencimiento',
                     'importe_total_pesos', 'total_cobrado', 'estado', 'check_cobranza')
            ->orderByDesc('fecha_factura')
            ->limit(200)
            ->get();
        return ResponseFactory::json($response, $facturas);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $cliente = $this->service->create($body, $user, $request);
        return ResponseFactory::json($response, $cliente, 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $cliente = $this->service->update((int) $args['id'], $body, $user, $request);
        return ResponseFactory::json($response, $cliente);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $this->service->delete((int) $args['id'], $user, $request);
        return ResponseFactory::noContent($response);
    }
}
