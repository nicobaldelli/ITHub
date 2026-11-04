<?php

/**
 * Compatibilidad: la lógica del lock vive en ITHub\Api\Support\CronLock
 * (compartida con CronController). Este archivo solo mantiene la función
 * global que usan los scripts.
 */

declare(strict_types=1);

use ITHub\Api\Support\CronLock;

/**
 * @return resource|null
 */
function ithub_cron_lock(string $basePath, string $nombre)
{
    return CronLock::adquirir($basePath, $nombre);
}
