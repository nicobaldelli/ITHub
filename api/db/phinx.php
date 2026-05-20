<?php

/**
 * Configuración de Phinx.
 * Para correr: vendor/bin/phinx migrate -c db/phinx.php
 * Usa el usuario de migraciones (con privilegios elevados), distinto al de runtime.
 */

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbPort = (int) ($_ENV['DB_PORT'] ?? 3306);
$dbName = $_ENV['DB_NAME'] ?? 'ithub';
// Para migraciones se usa preferentemente el user con privilegios; cae al runtime si no está definido
$dbUser = $_ENV['DB_MIGRATE_USER'] ?? $_ENV['DB_USER'] ?? 'ithub';
$dbPass = $_ENV['DB_MIGRATE_PASS'] ?? $_ENV['DB_PASS'] ?? '';

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
        'seeds' => __DIR__ . '/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $_ENV['APP_ENV'] ?? 'local',

        'local' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'port' => $dbPort,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],

        'staging' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'port' => $dbPort,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],

        'production' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'port' => $dbPort,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
