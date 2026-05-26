<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\FacturaRepository;
use ITHub\Api\Services\FacturaService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FacturasController
{
    private readonly FacturaRepository $repo;
    private readonly FacturaService $service;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->repo = $this->container->get(FacturaRepository::class);
        $this->service = $this->container->get(FacturaService::class);
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
        $factura = $this->repo->findById((int) $args['id']);
        if ($factura === null) {
            throw new NotFoundException('Factura no encontrada');
        }
        return ResponseFactory::json($response, $factura);
    }

    public function historial(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        if ($this->repo->findById($id) === null) {
            throw new NotFoundException('Factura no encontrada');
        }
        $events = Auditoria::where('entidad', 'factura')
            ->where('entidad_id', $id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        return ResponseFactory::json($response, $events);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $factura = $this->service->create($body, $user, $request);
        return ResponseFactory::json($response, $factura, 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $factura = $this->service->update((int) $args['id'], $body, $user, $request);
        return ResponseFactory::json($response, $factura);
    }

    public function checkCobranza(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $factura = $this->service->toggleCheckCobranza((int) $args['id'], $user, $request);
        return ResponseFactory::json($response, $factura);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $this->service->delete((int) $args['id'], $user, $request);
        return ResponseFactory::noContent($response);
    }

    /**
     * GET /cuotas-facturables?cliente_id=N
     *
     * Devuelve cuotas pendientes de servicios activos, filtradas opcionalmente
     * por cliente. Usado por /facturas/nueva para que el usuario elija cuota
     * cuando hace alta manual.
     */
    public function cuotasFacturables(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $clienteId = isset($params['cliente_id']) && $params['cliente_id'] !== ''
            ? (int) $params['cliente_id']
            : null;

        $q = ServicioCuota::query()
            ->with(['servicio:id,nombre,cliente_id,tipo,moneda,iva_porcentaje,template_factura', 'servicio.cliente:id,razon_social,cuit'])
            ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
            ->whereHas('servicio', function ($s) use ($clienteId): void {
                $s->where('estado', Servicio::ESTADO_ACTIVO)
                  ->whereNull('deleted_at');
                if ($clienteId !== null) {
                    $s->where('cliente_id', $clienteId);
                }
            })
            ->orderBy('fecha_prevista', 'asc')
            ->limit(500)
            ->get();

        return ResponseFactory::json($response, $q);
    }
}
