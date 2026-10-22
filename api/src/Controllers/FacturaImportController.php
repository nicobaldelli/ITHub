<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;
use ITHub\Api\Services\FacturaImportService;
use ITHub\Api\Support\MimeNormalizer;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * Import masivo de facturas históricas.
 *
 *  GET  /facturas/import/plantilla  → xlsx con cabeceras, ejemplos e instrucciones
 *  POST /facturas/import/preview    → multipart `archivo` (xlsx/csv) → filas + errores + token
 *  POST /facturas/import/confirm    → { token, omitir_con_error } → crea las facturas
 */
final class FacturaImportController
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MIMES = [
        'text/csv',
        'text/plain',
        MimeNormalizer::XLSX,
        'application/vnd.ms-excel',
    ];

    private readonly FacturaImportService $service;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->service = $this->container->get(FacturaImportService::class);
    }

    public function plantilla(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $out = $this->service->plantilla();

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo crear el stream de la plantilla');
        }
        fwrite($stream, $out['content']);
        rewind($stream);

        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['content']))
            ->withBody(new Stream($stream));
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        $files = $request->getUploadedFiles();
        if (empty($files['archivo'])) {
            throw new ValidationException('Campo `archivo` requerido (multipart)', ['archivo' => 'requerido']);
        }
        /** @var UploadedFileInterface $upload */
        $upload = $files['archivo'];
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Error al subir el archivo', ['archivo' => 'upload_error_' . $upload->getError()]);
        }
        if (($upload->getSize() ?? 0) > self::MAX_BYTES) {
            throw new ValidationException('El archivo supera los 5 MB', ['archivo' => 'demasiado grande']);
        }

        $nombre = $upload->getClientFilename() ?? 'import';
        $contenido = $upload->getStream()->getContents();

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectado = (string) ($finfo->buffer($contenido) ?: '');
        $mime = MimeNormalizer::resolve($detectado, (string) ($upload->getClientMediaType() ?: ''), $nombre, $contenido);
        if (!in_array($mime, self::MIMES, true)) {
            throw new ValidationException(
                'Formato no soportado: subí un .xlsx o un .csv',
                ['archivo' => "tipo {$mime}"]
            );
        }

        return ResponseFactory::json($response, $this->service->preview($contenido, $nombre, $user));
    }

    public function confirm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $token = (string) ($body['token'] ?? '');
        $omitir = filter_var($body['omitir_con_error'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return ResponseFactory::json($response, $this->service->confirm($token, $omitir, $user, $request));
    }
}
