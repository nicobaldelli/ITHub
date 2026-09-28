<?php

/**
 * Bootstrap de PHPUnit.
 *
 * Las variables de entorno las define phpunit.xml (<php><env>). PHPUnit las
 * carga en $_ENV y getenv(), que es lo que lee config/settings.php.
 * Acá solo se asegura el autoload, la timezone y que NO se cargue un .env
 * real por accidente (los tests nunca tocan la base de desarrollo).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set($_ENV['TIMEZONE'] ?? 'America/Argentina/Buenos_Aires');

// Defensa: si alguien corre phpunit sin phpunit.xml, no dejamos que settings.php
// llegue a leer credenciales de un .env real.
if (($_ENV['DB_DRIVER'] ?? '') !== 'sqlite' || ($_ENV['DB_NAME'] ?? '') !== ':memory:') {
    fwrite(STDERR, "Los tests solo corren con DB_DRIVER=sqlite y DB_NAME=:memory: (ver phpunit.xml)\n");
    exit(1);
}
