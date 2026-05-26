<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Listado y restauración de registros archivados (soft-deleted).
 *
 * Soporta:
 *  - clientes
 *  - facturas
 *  - servicios
 *
 * Los registros no se eliminan físicamente — el "delete" pone deleted_at.
 * Esta página los muestra y permite restaurarlos (deleted_at = NULL).
 */
final class ArchivadosController
{
    private readonly AuditoriaService $audit;

    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const ENTIDADES = [
        'clientes' => Cliente::class,
        'facturas' => FacturaVenta::class,
        'servicios' => Servicio::class,
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->audit = $this->container->get(AuditoriaService::class);
    }

    /**
     * GET /archivados?entidad=clientes|facturas|servicios
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $entidad = (string) ($params['entidad'] ?? 'clientes');

        if (!isset(self::ENTIDADES[$entidad])) {
            throw new ValidationException(
                'Entidad inválida',
                ['entidad' => 'permitidos: ' . implode(', ', array_keys(self::ENTIDADES))]
            );
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 25)));

        $modelClass = self::ENTIDADES[$entidad];
        /** @phpstan-ignore-next-line */
        $q = $modelClass::onlyTrashed();

        if ($entidad === 'facturas' || $entidad === 'servicios') {
            $q = $q->with('cliente:id,razon_social,cuit');
        }

        if (isset($params['search']) && $params['search'] !== '') {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $params['search']) . '%';
            $q = match ($entidad) {
                'clientes' => $q->where(function ($sub) use ($s) {
                    $sub->where('razon_social', 'like', $s)->orWhere('cuit', 'like', $s);
                }),
                'facturas' => $q->where('numero_factura', 'like', $s),
                'servicios' => $q->where('nombre', 'like', $s),
                default => $q,
            };
        }

        $q->orderByDesc('deleted_at');
        $paginator = $q->paginate(perPage: $perPage, page: $page);

        return ResponseFactory::json($response, $paginator->items(), 200, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    /**
     * POST /archivados/{entidad}/{id}/restaurar
     */
    public function restaurar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $entidad = (string) $args['entidad'];
        $id = (int) $args['id'];

        if (!isset(self::ENTIDADES[$entidad])) {
            throw new ValidationException(
                'Entidad inválida',
                ['entidad' => 'permitidos: ' . implode(', ', array_keys(self::ENTIDADES))]
            );
        }

        $modelClass = self::ENTIDADES[$entidad];
        /** @phpstan-ignore-next-line */
        $record = $modelClass::onlyTrashed()->find($id);
        if ($record === null) {
            throw new NotFoundException('Registro archivado no encontrado');
        }

        $record->restore();

        $this->audit->log(
            $actor->id,
            $entidad,
            $id,
            Auditoria::ACCION_EDITAR,
            ['accion' => 'restaurado'],
            $request
        );

        return ResponseFactory::json($response, $record->fresh());
    }
}
