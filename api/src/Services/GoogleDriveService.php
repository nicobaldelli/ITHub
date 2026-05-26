<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Google\Client as GoogleClient;
use Google\Service\Drive as DriveService;
use Google\Service\Drive\DriveFile;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\ConfigApp;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wrapper sobre google/apiclient para subir/listar/borrar adjuntos en Drive.
 *
 * Configuración requerida:
 *  - Service account JSON en GOOGLE_SERVICE_ACCOUNT_JSON_PATH (env)
 *  - GOOGLE_IMPERSONATE_USER si se quiere impersonar a un usuario del workspace
 *  - drive_root_folder_id en config_app (carpeta raíz donde se crea año/mes/cliente)
 *
 * Estructura creada en Drive:
 *   <root>/<año>/<mm-mes>/<cliente_razon_social>/<archivo>
 *
 * Scope mínimo: drive.file (solo archivos creados por la app, no acceso global).
 */
final class GoogleDriveService
{
    private readonly string $serviceAccountPath;
    private readonly string $impersonateUser;
    /** @var string[] */
    private readonly array $allowedMimes;
    private readonly int $maxFileSize;

    private ?DriveService $driveCache = null;

    public function __construct(
        ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
        $settings = $container->get('settings')['google_drive'] ?? [];
        $basePath = $container->get('basePath');

        // service_account_path puede ser absoluto o relativo al basePath
        $path = (string) ($settings['service_account_path'] ?? '');
        $this->serviceAccountPath = $path !== '' && $path[0] === '/'
            ? $path
            : $basePath . '/' . $path;
        $this->impersonateUser = (string) ($settings['impersonate_user'] ?? '');
        $this->allowedMimes = (array) ($settings['allowed_mimes'] ?? []);
        $this->maxFileSize = (int) ($settings['max_file_size_bytes'] ?? 25 * 1024 * 1024);
    }

    /**
     * Devuelve true si Drive está disponible para usar (credenciales + root folder).
     */
    public function isAvailable(): bool
    {
        return $this->getRootFolderId() !== ''
            && is_readable($this->serviceAccountPath);
    }

    /**
     * Sube un archivo a la carpeta año/mes/cliente.
     *
     * @param resource|string $bodyOrPath stream con el contenido o path al archivo subido
     * @return array{drive_file_id:string, drive_view_url:string|null, drive_download_url:string|null, mime_type:string, tamanio_bytes:int}
     */
    public function uploadFacturaArchivo(
        string $clienteRazonSocial,
        \DateTimeInterface $fechaFactura,
        string $nombreArchivo,
        string $mimeType,
        int $tamanioBytes,
        mixed $bodyOrPath,
    ): array {
        $this->ensureAvailable();
        $this->validarUpload($nombreArchivo, $mimeType, $tamanioBytes);

        $drive = $this->getDriveService();
        $rootFolderId = $this->getRootFolderId();

        $anio = $fechaFactura->format('Y');
        $mes = $fechaFactura->format('m') . '-' . self::nombreMes((int) $fechaFactura->format('n'));
        $clienteSafe = self::sanitizeFolderName($clienteRazonSocial);

        $folderAnio = $this->ensureFolder($drive, $anio, $rootFolderId);
        $folderMes = $this->ensureFolder($drive, $mes, $folderAnio);
        $folderCliente = $this->ensureFolder($drive, $clienteSafe, $folderMes);

        $file = new DriveFile();
        $file->setName(self::sanitizeFileName($nombreArchivo));
        $file->setParents([$folderCliente]);

        $content = is_resource($bodyOrPath)
            ? stream_get_contents($bodyOrPath)
            : (is_string($bodyOrPath) && is_file($bodyOrPath) ? file_get_contents($bodyOrPath) : (string) $bodyOrPath);

        $created = $drive->files->create(
            $file,
            [
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id, name, mimeType, size, webViewLink, webContentLink',
            ]
        );

        $this->logger->info('drive.uploaded', [
            'file_id' => $created->id,
            'name' => $created->name,
            'folder' => "{$anio}/{$mes}/{$clienteSafe}",
        ]);

        return [
            'drive_file_id' => $created->id,
            'drive_view_url' => $created->webViewLink ?? null,
            'drive_download_url' => $created->webContentLink ?? null,
            'mime_type' => $created->mimeType ?? $mimeType,
            'tamanio_bytes' => (int) ($created->size ?? $tamanioBytes),
        ];
    }

    public function deleteFile(string $fileId): void
    {
        $this->ensureAvailable();
        $drive = $this->getDriveService();
        $drive->files->delete($fileId);
        $this->logger->info('drive.deleted', ['file_id' => $fileId]);
    }

    /**
     * Genera un link de descarga temporal (o el webContentLink si está disponible).
     */
    public function getDownloadUrl(string $fileId): ?string
    {
        $this->ensureAvailable();
        $drive = $this->getDriveService();
        try {
            $file = $drive->files->get($fileId, ['fields' => 'id, webContentLink, webViewLink']);
            return $file->webContentLink ?? $file->webViewLink ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning('drive.get_download_url.failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    private function ensureAvailable(): void
    {
        if (!is_readable($this->serviceAccountPath)) {
            throw new ValidationException(
                'Google Drive no configurado: falta service-account.json o no es legible',
                ['drive' => 'service_account_missing']
            );
        }
        if ($this->getRootFolderId() === '') {
            throw new ValidationException(
                'Google Drive no configurado: falta drive_root_folder_id en /configuracion',
                ['drive' => 'root_folder_missing']
            );
        }
    }

    private function getRootFolderId(): string
    {
        $row = ConfigApp::where('clave', 'drive_root_folder_id')->first();
        return $row?->valor !== null ? (string) ($row->valor ?? '') : '';
    }

    private function getDriveService(): DriveService
    {
        if ($this->driveCache !== null) {
            return $this->driveCache;
        }

        $client = new GoogleClient();
        $client->setAuthConfig($this->serviceAccountPath);
        $client->setScopes([DriveService::DRIVE_FILE]);

        if ($this->impersonateUser !== '') {
            $client->setSubject($this->impersonateUser);
        }

        $this->driveCache = new DriveService($client);
        return $this->driveCache;
    }

    /**
     * Devuelve el ID de la carpeta `$name` dentro de `$parentId`.
     * Si no existe, la crea.
     */
    private function ensureFolder(DriveService $drive, string $name, string $parentId): string
    {
        $nameEsc = str_replace("'", "\\'", $name);
        $q = sprintf(
            "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false",
            $nameEsc,
            $parentId
        );
        $list = $drive->files->listFiles([
            'q' => $q,
            'fields' => 'files(id, name)',
            'pageSize' => 1,
        ]);
        $files = $list->getFiles();
        if (count($files) > 0) {
            return $files[0]->id;
        }

        $folder = new DriveFile();
        $folder->setName($name);
        $folder->setMimeType('application/vnd.google-apps.folder');
        $folder->setParents([$parentId]);

        $created = $drive->files->create($folder, ['fields' => 'id']);
        return $created->id;
    }

    private function validarUpload(string $nombre, string $mime, int $size): void
    {
        if ($size <= 0) {
            throw new ValidationException('Archivo vacío', ['archivo' => 'vacio']);
        }
        if ($size > $this->maxFileSize) {
            throw new ValidationException(
                sprintf('Archivo demasiado grande (máximo %d MB)', (int) ($this->maxFileSize / (1024 * 1024))),
                ['archivo' => 'tamaño excedido']
            );
        }
        if (count($this->allowedMimes) > 0 && !in_array($mime, $this->allowedMimes, true)) {
            throw new ValidationException(
                'Tipo de archivo no permitido',
                ['archivo' => 'mime ' . $mime . ' no permitido']
            );
        }
        if (mb_strlen($nombre) > 255 || str_contains($nombre, '..') || str_contains($nombre, '/')) {
            throw new ValidationException('Nombre de archivo inválido', ['archivo' => 'nombre']);
        }
    }

    private static function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $name) ?? 'archivo';
        return mb_substr($name, 0, 200);
    }

    private static function sanitizeFolderName(string $name): string
    {
        $name = preg_replace('/[\\/<>:"|?*]/u', '_', $name) ?? 'cliente';
        $name = trim($name);
        return $name === '' ? 'cliente_sin_nombre' : mb_substr($name, 0, 100);
    }

    private static function nombreMes(int $n): string
    {
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        return $meses[$n] ?? 'Mes';
    }
}
