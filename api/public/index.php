<?php

/**
 * ITHub API - Front controller (entry point)
 *
 * Apache/Nginx debe apuntar el DocumentRoot a este directorio (public/).
 * Todo el resto del código está fuera del web root para que no sea servido.
 */

declare(strict_types=1);

use ITHub\Api\Bootstrap\App;

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap de la aplicación
$app = (new App(dirname(__DIR__)))->build();

// Ejecutar
$app->run();
