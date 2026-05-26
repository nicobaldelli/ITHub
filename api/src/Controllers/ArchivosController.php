<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\FacturaArchivo;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Services\GoogleDriveService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * CRUD de archivos adjuntos de una factura, almacenados en Google Drive.
 *
 * Los metadatos viven en `factura_archivos` (id, drive_file_id, urls,
 * etc.). El contenido físico está en Drive.
 *
 * Si Google Drive no está configurado (falta service-account.json o
 * drive_root_folder_id), los endpoints de upload devuelven 422 con un
 * mensaje claro y el listado funciona vacío.
 */
final class ArchivosController
{
    private readonly GoogleDriveService $drive;
    private readonly AuditoriaService $audit;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->drive = $this->container->get(GoogleDriveService::class);
        $this->audit = $this->container->get(AuditoriaService::class);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $facturaId = (int) $args['id'];
        $this->resolveFactura($facturaId);

        $archivos = FacturaArchivo::where('factura_id', $facturaId)
            ->orderByDesc('created_at')
            ->get();

        return ResponseFactory::json($response, [
            'archivos' => $archivos,
            'drive_disponible' => $this->drive->isAvailable(),
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $facturaId = (int) $args['id'];
        $factura = $this->resolveFactura($facturaId);

        if (!$this->drive->isAvailable()) {
            throw new ValidationException(
                'Google Drive no está configurado. Configurá drive_root_folder_id en /configuracion y el service account JSON en el servidor.',
                ['drive' => 'no disponible']
            );
        }

        $files = $request->getUploadedFiles();
        if (empty($files['archivo'])) {
            throw new ValidationException('Campo `archivo` requerido (multipart)', ['archivo' => 'requerido']);
        }
        /** @var UploadedFileInterface $upload */
        $upload = $files['archivo'];

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Error al subir el archivo', ['archivo' => 'upload_error_' . $upload->getError()]);
        }

        // Magic bytes check con finfo (defensa en profundidad)
        $tmpStream = $upload->getStream();
        $bytes = $tmpStream->read(512);
        $tmpStream->rewind();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) ($finfo->buffer($bytes) ?: 'application/octet-stream');
        $reportedMime = $upload->getClientMediaType() ?: $detectedMime;

        // El cliente declara X mime, finfo detecta Y mime. Usamos el detectado para
        // validar contra la whitelist (más seguro). Aunque permitimos que cierto tipo
        // de archivos como CSV puedan reportar texto/plain o application/csv.
        $mimeFinal = $detectedMime !== '' ? $detectedMime : $reportedMime;

        $clienteNombre = $factura->cliente?->razon_social ?? 'cliente';
        $fechaFactura = $factura->fecha_factura ?? new \DateTimeImmutable();

        $result = $this->drive->uploadFacturaArchivo(
            $clienteNombre,
            $fechaFactura,
            $upload->getClientFilename() ?? 'archivo',
            $mimeFinal,
            $upload->getSize() ?? 0,
            $tmpStream->getContents(),
        );

        $archivo = FacturaArchivo::create([
            'factura_id' => $factura->id,
            'drive_file_id' => $result['drive_file_id'],
            'nombre_archivo' => $upload->getClientFilename() ?? 'archivo',
            'mime_type' => $result['mime_type'],
            'tamanio_bytes' => $result['tamanio_bytes'],
            'drive_view_url' => $result['drive_view_url'],
            'drive_download_url' => $result['drive_download_url'],
            'uploaded_by' => $user->id,
        ]);

        $this->audit->log(
            $user->id,
            'factura_archivo',
            $archivo->id,
            Auditoria::ACCION_ARCHIVO_SUBIDO,
            ['factura_id' => $factura->id, 'nombre' => $archivo->nombre_archivo, 'size' => $archivo->tamanio_bytes],
            $request
        );

        return ResponseFactory::json($response, $archivo, 201);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $facturaId = (int) $args['id'];
        $archivoId = (int) $args['archivoId'];

        $archivo = FacturaArchivo::where('id', $archivoId)
            ->where('factura_id', $facturaId)
            ->first();
        if ($archivo === null) {
            throw new NotFoundException('Archivo no encontrado');
        }

        // Intentamos borrar en Drive primero; si falla, igual seguimos
        try {
            if ($this->drive->isAvailable()) {
                $this->drive->deleteFile($archivo->drive_file_id);
            }
        } catch (\Throwable $e) {
            // Logueamos pero no abortamos; queremos limpiar la fila igual
            error_log('Drive delete failed: ' . $e->getMessage());
        }

        $archivo->delete();

        $this->audit->log(
            $user->id,
            'factura_archivo',
            $archivoId,
            Auditoria::ACCION_ARCHIVO_ELIMINADO,
            ['factura_id' => $facturaId, 'nombre' => $archivo->nombre_archivo],
            $request
        );

        return ResponseFactory::noContent($response);
    }

    private function resolveFactura(int $id): FacturaVenta
    {
        $f = FacturaVenta::with('cliente:id,razon_social')->find($id);
        if ($f === null) {
            throw new NotFoundException('Factura no encontrada');
        }
        return $f;
    }
}
