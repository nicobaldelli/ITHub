<?php

/**
 * Lock por archivo para los scripts de cron: evita que dos ejecuciones del
 * mismo script se solapen (por ejemplo si el cron tarda más que el intervalo
 * o si se dispara a mano mientras corre el programado).
 *
 * Devuelve el handle del lock (mantenerlo vivo hasta que termine el script;
 * PHP lo libera solo al salir) o null si ya hay otra instancia corriendo.
 */

declare(strict_types=1);

/**
 * @return resource|null
 */
function ithub_cron_lock(string $basePath, string $nombre)
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
