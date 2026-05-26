<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Services\ExportService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * Endpoints de exportación de listados (Excel, CSV, PDF).
 *
 * Hoy soportado:
 *  - GET /facturas/export?formato=xlsx|csv|pdf con los mismos filtros del listado
 */
final class ExportController
{
    private readonly ExportService $service;
    private readonly AuditoriaService $audit;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->service = $this->container->get(ExportService::class);
        $this->audit = $this->container->get(AuditoriaService::class);
    }

    public function facturas(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $formato = strtolower((string) ($params['formato'] ?? 'xlsx'));

        $out = $this->service->exportFacturas($params, $formato);

        $this->audit->log($user->id, 'factura', null, Auditoria::ACCION_EXPORT, [
            'formato' => $formato,
            'filtros' => array_intersect_key($params, array_flip([
                'tipo', 'estado', 'moneda', 'cliente_id', 'fecha_desde', 'fecha_hasta',
                'search', 'cobrado', 'vencidas',
            ])),
        ], $request);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo crear stream para la exportación');
        }
        fwrite($stream, $out['content']);
        rewind($stream);

        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['content']))
            ->withBody(new Stream($stream));
    }
}
