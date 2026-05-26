<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Visor de la bitácora de auditoría (solo admin).
 *
 * Read-only — la auditoría es inmutable por diseño.
 */
final class AuditoriaController
{
    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($params['per_page'] ?? 50)));

        $q = Auditoria::query()->with('user:id,nombre,apellido,email');

        if (!empty($params['entidad'])) {
            $q->where('entidad', (string) $params['entidad']);
        }
        if (!empty($params['entidad_id'])) {
            $q->where('entidad_id', (int) $params['entidad_id']);
        }
        if (!empty($params['accion'])) {
            $q->where('accion', (string) $params['accion']);
        }
        if (!empty($params['user_id'])) {
            $q->where('user_id', (int) $params['user_id']);
        }
        if (!empty($params['from'])) {
            $q->where('created_at', '>=', (string) $params['from']);
        }
        if (!empty($params['to'])) {
            // hasta fin del día indicado
            $q->where('created_at', '<=', $params['to'] . ' 23:59:59');
        }

        $q->orderByDesc('created_at')->orderByDesc('id');

        $paginator = $q->paginate(perPage: $perPage, page: $page);

        $items = collect($paginator->items())->map(function (Auditoria $a) {
            return [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'user' => $a->user
                    ? [
                        'id' => $a->user->id,
                        'nombre' => $a->user->nombre,
                        'apellido' => $a->user->apellido,
                        'email' => $a->user->email,
                    ]
                    : null,
                'entidad' => $a->entidad,
                'entidad_id' => $a->entidad_id,
                'accion' => $a->accion,
                'campos_modificados' => $a->campos_modificados,
                'ip' => $a->ip,
                'user_agent' => $a->user_agent,
                'request_id' => $a->request_id,
                'created_at' => $a->created_at?->format('c'),
            ];
        });

        return ResponseFactory::json($response, $items->all(), 200, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }
}
