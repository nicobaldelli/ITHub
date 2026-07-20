<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Services\BackupService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * Exportación e importación de la copia de seguridad de datos (solo admin).
 *
 * - GET  /backup/export  → descarga ithub-backup-YYYYMMDD-HHMM.json
 * - POST /backup/import  → multipart con campo `archivo` (el JSON exportado)
 *
 * El import REEMPLAZA todos los datos de negocio del entorno. La UI exige
 * confirmación explícita antes de llamar.
 */
final class BackupController
{
    private const MAX_IMPORT_BYTES = 100 * 1024 * 1024; // 100 MB

    private readonly BackupService $service;
    private readonly AuditoriaService $audit;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->service = $this->container->get(BackupService::class);
        $this->audit = $this->container->get(AuditoriaService::class);
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        $data = $this->service->export();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar la copia de seguridad');
        }

        $filename = 'ithub-backup-' . date('Ymd-Hi') . '.json';

        $this->audit->log($user->id, 'backup', null, Auditoria::ACCION_EXPORT, [
            'tipo' => 'copia_seguridad_datos',
            'archivo' => $filename,
            'bytes' => strlen($json),
        ], $request);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo crear el stream');
        }
        fwrite($stream, $json);
        rewind($stream);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($json))
            ->withBody(new Stream($stream));
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        $files = $request->getUploadedFiles();
        $archivo = $files['archivo'] ?? null;
        if (!$archivo instanceof UploadedFileInterface || $archivo->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Subí el archivo de copia de seguridad (campo `archivo`)',
                ['archivo' => 'requerido']);
        }
        if (($archivo->getSize() ?? 0) > self::MAX_IMPORT_BYTES) {
            throw new ValidationException('Archivo demasiado grande (máximo 100 MB)',
                ['archivo' => 'excede el límite']);
        }

        $contenido = $archivo->getStream()->getContents();
        $payload = json_decode($contenido, true);
        if (!is_array($payload)) {
            throw new ValidationException('El archivo no es un JSON válido',
                ['archivo' => json_last_error_msg()]);
        }

        $resumen = $this->service->import($payload, $user);

        $this->audit->log($user->id, 'backup', null, Auditoria::ACCION_IMPORT, [
            'tipo' => 'copia_seguridad_datos',
            'archivo' => $archivo->getClientFilename(),
            'resumen' => $resumen,
        ], $request);

        return ResponseFactory::json($response, array_merge($resumen, [
            'mensaje' => 'Datos restaurados. Las sesiones anteriores quedaron invalidadas: volvé a iniciar sesión.',
        ]));
    }
}
