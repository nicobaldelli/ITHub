<?php

declare(strict_types=1);

namespace ITHub\Api\Support;

/**
 * Lock por archivo para las tareas de cron, compartido entre el script CLI
 * (scripts/cron_diario.php) y los endpoints HTTP (CronController): dos
 * corridas de la misma tarea no se pueden solapar vengan de donde vengan.
 * Sin esto, el rolling window duplica cuotas si el cron programado y un
 * disparo manual desde la UI coinciden.
 *
 * El handle devuelto hay que mantenerlo vivo hasta que termine la tarea;
 * PHP libera el flock al cerrarlo o al terminar el proceso/request.
 */
final class CronLock
{
    /** Nombre del lock que comparten el cron diario y sus tareas sueltas. */
    public const DIARIO = 'cron_diario';

    /**
     * @return resource|null handle con el lock tomado, o null si ya está tomado
     */
    public static function adquirir(string $basePath, string $nombre)
    {
        $dir = $basePath . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $handle = fopen($dir . '/' . $nombre . '.lock', 'c');
        if ($handle === false) {
            // Sin lock preferimos correr igual antes que dejar de facturar
            return fopen('php://memory', 'r+') ?: null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /** @param resource|null $handle */
    public static function liberar($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
