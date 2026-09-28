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

        // Magic bytes check con finfo sobre el archivo COMPLETO (defensa en
        // profundidad). Con solo los primeros bytes finfo no distingue un xlsx/docx
        // de un zip cualquiera.
        $contenido = $upload->getStream()->getContents();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) ($finfo->buffer($contenido) ?: 'application/octet-stream');
        $reportedMime = (string) ($upload->getClientMediaType() ?: '');
        $nombreArchivo = $upload->getClientFilename() ?? 'archivo';

        $mimeFinal = $this->normalizarMime($detectedMime, $reportedMime, $nombreArchivo, $contenido);

        $clienteNombre = $factura->cliente?->razon_social ?? 'cliente';
        $fechaFactura = $factura->fecha_factura ?? new \DateTimeImmutable();

        $result = $this->drive->uploadFacturaArchivo(
            $clienteNombre,
            $fechaFactura,
            $nombreArchivo,
            $mimeFinal,
            strlen($contenido),
            $contenido,
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

    /**
     * Resuelve el MIME a validar contra la whitelist. Se prioriza lo que detecta
     * finfo (magic bytes), pero hay dos casos en los que finfo no alcanza y se
     * confirma con una segunda verificación sobre el contenido:
     *  - xlsx/docx: finfo devuelve application/zip. Son válidos solo si el zip
     *    contiene el manifiesto OOXML "[Content_Types].xml".
     *  - csv: finfo devuelve text/plain. Se acepta como text/csv solo si el
     *    cliente lo declaró csv o la extensión es .csv.
     */
    private function normalizarMime(string $detected, string $reported, string $nombre, string $contenido): string
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $officeMimes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        if (
            in_array($detected, ['application/zip', 'application/octet-stream'], true)
            && isset($officeMimes[$ext])
            && str_starts_with($contenido, "PK\x03\x04")
            && str_contains($contenido, '[Content_Types].xml')
        ) {
            return $officeMimes[$ext];
        }

        if (
            $detected === 'text/plain'
            && ($ext === 'csv' || in_array($reported, ['text/csv', 'application/csv'], true))
        ) {
            return 'text/csv';
        }

        return $detected !== '' ? $detected : $reported;
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
