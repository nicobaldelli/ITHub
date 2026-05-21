<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\ServicioRepository;
use ITHub\Api\Services\ServicioAjusteService;
use ITHub\Api\Services\ServicioCuotaService;
use ITHub\Api\Services\ServicioService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ServiciosController
{
    private readonly ServicioRepository $repo;
    private readonly ServicioService $service;
    private readonly ServicioCuotaService $cuotaService;
    private readonly ServicioAjusteService $ajusteService;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->repo = $this->container->get(ServicioRepository::class);
        $this->service = $this->container->get(ServicioService::class);
        $this->cuotaService = $this->container->get(ServicioCuotaService::class);
        $this->ajusteService = $this->container->get(ServicioAjusteService::class);
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
        $servicio = $this->repo->findById((int) $args['id'], withCuotas: true);
        if ($servicio === null) {
            throw new NotFoundException('Servicio no encontrado');
        }
        return ResponseFactory::json($response, $servicio);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $servicio = $this->service->create($body, $user, $request);
        return ResponseFactory::json($response, $servicio, 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $servicio = $this->service->update((int) $args['id'], $body, $user, $request);
        return ResponseFactory::json($response, $servicio);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $this->service->delete((int) $args['id'], $user, $request);
        return ResponseFactory::noContent($response);
    }

    // ============================================================
    // ACCIONES DE ESTADO DEL SERVICIO
    // ============================================================

    public function pausar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $s = $this->service->pausar((int) $args['id'], $user, $request);
        return ResponseFactory::json($response, $s);
    }

    public function reanudar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $modo = (string) ($body['modo'] ?? 'cancelar_pasadas');
        $s = $this->service->reanudar((int) $args['id'], $modo, $user, $request);
        return ResponseFactory::json($response, $s);
    }

    public function cancelar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $s = $this->service->cancelar((int) $args['id'], $user, $request);
        return ResponseFactory::json($response, $s);
    }

    public function extender(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $s = $this->service->extender((int) $args['id'], $body, $user, $request);
        return ResponseFactory::json($response, $s);
    }

    // ============================================================
    // ACCIONES SOBRE CUOTAS
    // ============================================================

    public function editarCuota(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $cuota = $this->cuotaService->editar((int) $args['id'], (int) $args['cid'], $body, $user, $request);
        return ResponseFactory::json($response, $cuota);
    }

    public function omitirCuota(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $cuota = $this->cuotaService->omitir((int) $args['id'], (int) $args['cid'], $user, $request);
        return ResponseFactory::json($response, $cuota);
    }

    public function cancelarCuota(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $cuota = $this->cuotaService->cancelar((int) $args['id'], (int) $args['cid'], $user, $request);
        return ResponseFactory::json($response, $cuota);
    }

    public function facturarCuota(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $factura = $this->cuotaService->facturar((int) $args['id'], (int) $args['cid'], $body, $user, $request);
        return ResponseFactory::json($response, $factura, 201);
    }

    // ============================================================
    // AJUSTES DE TARIFA
    // ============================================================

    public function listarAjustes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return ResponseFactory::json($response, $this->ajusteService->listar((int) $args['id']));
    }

    public function crearAjuste(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $a = $this->ajusteService->create((int) $args['id'], $body, $user, $request);
        return ResponseFactory::json($response, $a, 201);
    }

    public function aplicarAjuste(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $a = $this->ajusteService->aplicar((int) $args['aid'], $user, $request);
        return ResponseFactory::json($response, $a);
    }

    public function eliminarAjuste(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $this->ajusteService->eliminar((int) $args['aid'], $user, $request);
        return ResponseFactory::noContent($response);
    }
}
