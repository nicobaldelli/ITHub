<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\User;
use ITHub\Api\Services\UsuarioService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class UsuariosController
{
    private readonly UsuarioService $service;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->service = $this->container->get(UsuarioService::class);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 25)));

        $paginator = $this->service->paginate($params, $page, $perPage);
        return ResponseFactory::json($response, $paginator->items(), 200, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $u = $this->service->findById((int) $args['id']);
        return ResponseFactory::json($response, $u);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $result = $this->service->create($body, $actor, $request);
        return ResponseFactory::json($response, [
            'user' => $result['user'],
            'password_temporal' => $result['password_temporal'],
            'must_change_password' => true,
        ], 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $u = $this->service->update((int) $args['id'], $body, $actor, $request);
        return ResponseFactory::json($response, $u);
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $result = $this->service->resetPassword((int) $args['id'], $actor, $request);
        return ResponseFactory::json($response, [
            'user' => $result['user'],
            'password_temporal' => $result['password_temporal'],
        ]);
    }

    public function activar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $u = $this->service->activar((int) $args['id'], $actor, $request);
        return ResponseFactory::json($response, $u);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $u = $this->service->desactivar((int) $args['id'], $actor, $request);
        return ResponseFactory::json($response, $u);
    }
}
