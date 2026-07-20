<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;
use Psr\Log\LoggerInterface;

/**
 * Exporta e importa una copia de seguridad de los DATOS de negocio en JSON.
 *
 * Pensado para mover el dataset completo entre entornos (local <-> produccion)
 * del mismo esquema. NO es un backup de esquema: las tablas deben existir y
 * estar en la MISMA version de migraciones en origen y destino (se valida
 * contra phinxlog y se rechaza el import si difieren).
 *
 * Alcance (en orden de dependencia FK):
 *  - users                    (incluye password_hash: los logins viajan con los datos)
 *  - clientes
 *  - servicios
 *  - servicio_cuotas
 *  - servicio_ajustes
 *  - facturas_venta
 *  - factura_archivos
 *  - notificaciones_enviadas  (se incluye para NO re-enviar mails tras restaurar)
 *
 * Excluidos a propósito:
 *  - config_app       (configuracion propia de cada entorno: SMTP, Drive, etc.)
 *  - refresh_tokens   (sesiones, no tienen sentido fuera de su entorno)
 *  - auditoria        (bitacora inmutable de CADA entorno)
 *  - phinxlog         (lo maneja Phinx)
 *
 * El import es REEMPLAZO TOTAL: borra el contenido actual de las tablas del
 * alcance y carga el del archivo, preservando IDs (asi las FKs internas quedan
 * consistentes). Corre en una transaccion: si algo falla, no queda a medias.
 */
final class BackupService
{
    public const FORMATO = 'ithub-backup';
    public const VERSION_FORMATO = 1;

    /** Orden de inserción (padres primero). El borrado usa el orden inverso. */
    private const TABLAS = [
        'users',
        'clientes',
        'servicios',
        'servicio_cuotas',
        'servicio_ajustes',
        'facturas_venta',
        'factura_archivos',
        'notificaciones_enviadas',
    ];

    private const CHUNK_INSERT = 200;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Genera la estructura completa del backup.
     *
     * @return array{formato:string, version_formato:int, schema_version:string|null, generado_en:string, tablas:array<string, array<int, array<string,mixed>>>}
     */
    public function export(): array
    {
        $tablas = [];
        foreach (self::TABLAS as $tabla) {
            $rows = Capsule::table($tabla)->get();
            $tablas[$tabla] = array_map(
                static fn (object $r): array => (array) $r,
                $rows->all(),
            );
        }

        return [
            'formato' => self::FORMATO,
            'version_formato' => self::VERSION_FORMATO,
            'schema_version' => $this->schemaVersion(),
            'generado_en' => date('c'),
            'tablas' => $tablas,
        ];
    }

    /**
     * Restaura el backup reemplazando los datos actuales.
     *
     * @param array<string,mixed> $payload JSON decodificado del archivo subido
     * @return array{tablas_restauradas:int, filas_insertadas:int}
     */
    public function import(array $payload, User $actor): array
    {
        // --- Validaciones de formato ---
        if (($payload['formato'] ?? null) !== self::FORMATO) {
            throw new ValidationException(
                'El archivo no es una copia de seguridad de ITHub',
                ['archivo' => 'formato desconocido']
            );
        }
        if ((int) ($payload['version_formato'] ?? 0) !== self::VERSION_FORMATO) {
            throw new ValidationException(
                'Versión de formato incompatible',
                ['archivo' => 'version_formato ' . ($payload['version_formato'] ?? '?')]
            );
        }

        $schemaBackup = $payload['schema_version'] ?? null;
        $schemaActual = $this->schemaVersion();
        if ($schemaBackup !== $schemaActual) {
            throw new ValidationException(
                sprintf(
                    'La copia fue generada con otra versión del esquema (copia: %s, este entorno: %s). '
                    . 'Corré las migraciones pendientes en ambos entornos y regenerá la copia.',
                    $schemaBackup ?? 'desconocida',
                    $schemaActual ?? 'desconocida',
                ),
                ['schema_version' => 'incompatible']
            );
        }

        $tablas = $payload['tablas'] ?? null;
        if (!is_array($tablas)) {
            throw new ValidationException('Estructura inválida', ['tablas' => 'faltante']);
        }

        // Solo tablas del alcance; cualquier otra clave se ignora con warning
        foreach (array_keys($tablas) as $t) {
            if (!in_array($t, self::TABLAS, true)) {
                $this->logger->warning('backup.import.tabla_ignorada', ['tabla' => $t]);
                unset($tablas[$t]);
            }
        }

        // El backup debe traer al menos un admin activo — si no, al restaurar
        // nadie podria loguearse.
        $tieneAdmin = false;
        foreach (($tablas['users'] ?? []) as $u) {
            if (($u['rol'] ?? '') === 'admin' && (int) ($u['activo'] ?? 0) === 1) {
                $tieneAdmin = true;
                break;
            }
        }
        if (!$tieneAdmin) {
            throw new ValidationException(
                'La copia no contiene ningún usuario admin activo — restaurarla te dejaría sin acceso',
                ['users' => 'sin admin activo']
            );
        }

        $filas = 0;
        $conn = Capsule::connection();

        $conn->transaction(function () use ($conn, $tablas, &$filas): void {
            $conn->statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                // Borrar en orden inverso (hijos primero)
                foreach (array_reverse(self::TABLAS) as $tabla) {
                    $conn->table($tabla)->delete();
                }

                // Insertar en orden (padres primero), preservando IDs
                foreach (self::TABLAS as $tabla) {
                    $rows = $tablas[$tabla] ?? [];
                    foreach (array_chunk($rows, self::CHUNK_INSERT) as $chunk) {
                        // Sanitizar: solo arrays planos (valores escalares o null)
                        $chunk = array_map(static function (array $row): array {
                            foreach ($row as $k => $v) {
                                if (is_array($v) || is_object($v)) {
                                    $row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                                }
                            }
                            return $row;
                        }, $chunk);
                        $conn->table($tabla)->insert($chunk);
                        $filas += count($chunk);
                    }
                }
            } finally {
                $conn->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });

        $this->logger->info('backup.import.ok', [
            'actor' => $actor->id,
            'filas' => $filas,
        ]);

        return [
            'tablas_restauradas' => count(self::TABLAS),
            'filas_insertadas' => $filas,
        ];
    }

    /**
     * Versión del esquema = última migración aplicada según phinxlog.
     */
    private function schemaVersion(): ?string
    {
        try {
            $v = Capsule::table('phinxlog')->max('version');
            return $v !== null ? (string) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
