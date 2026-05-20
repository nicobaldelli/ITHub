<?php

/**
 * Configuración global de la API.
 * Lee del entorno (.env cargado en App::build).
 * Valida claves obligatorias y aborta si faltan.
 */

declare(strict_types=1);

/**
 * Helper para leer del entorno con default y casting de tipo.
 */
function env_get(string $key, mixed $default = null, string $cast = 'string'): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match ($cast) {
        'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        'int' => (int) $value,
        'array' => array_filter(array_map('trim', explode(',', (string) $value))),
        default => (string) $value,
    };
}

/**
 * Aborta si falta una variable obligatoria.
 */
function env_required(string $key): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        throw new RuntimeException("Variable de entorno obligatoria no definida: {$key}");
    }
    return (string) $value;
}

$env = env_get('APP_ENV', 'production');
$isProduction = $env === 'production';

return [
    'app' => [
        'name' => env_get('APP_NAME', 'ITHub API'),
        'env' => $env,
        'debug' => env_get('APP_DEBUG', false, 'bool'),
        'url' => env_required('APP_URL'),
        'frontend_url' => env_required('FRONTEND_URL'),
        'timezone' => env_get('TIMEZONE', 'America/Argentina/Buenos_Aires'),
        'is_production' => $isProduction,
    ],

    'db' => [
        'driver' => env_get('DB_DRIVER', 'mysql'),
        'host' => env_required('DB_HOST'),
        'port' => env_get('DB_PORT', 3306, 'int'),
        'database' => env_required('DB_NAME'),
        'username' => env_required('DB_USER'),
        'password' => env_required('DB_PASS'),
        'charset' => env_get('DB_CHARSET', 'utf8mb4'),
        'collation' => env_get('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => '',
        'options' => [
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, // prepared statements reales
        ],
    ],

    'jwt' => [
        'secret' => env_required('JWT_SECRET'),
        'algo' => 'HS256',
        'issuer' => env_required('JWT_ISSUER'),
        'audience' => env_required('JWT_AUDIENCE'),
        'access_ttl' => env_get('JWT_ACCESS_TTL', 900, 'int'),
        'refresh_ttl' => env_get('JWT_REFRESH_TTL', 604800, 'int'),
    ],

    'cookie' => [
        'domain' => env_required('COOKIE_DOMAIN'),
        'secure' => env_get('COOKIE_SECURE', true, 'bool'),
        'samesite' => env_get('COOKIE_SAMESITE', 'Strict'),
        'httponly' => true,
        'refresh_path' => '/api/v1/auth',
        'refresh_name' => 'ithub_refresh',
        'csrf_name' => 'ithub_csrf',
    ],

    'cors' => [
        'allowed_origins' => array_filter([
            env_required('FRONTEND_URL'),
            $env === 'local' ? 'http://localhost:3000' : null,
        ]),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-Token', 'X-Request-ID'],
        'exposed_headers' => ['X-Request-ID'],
        'allow_credentials' => true,
        'max_age' => 86400,
    ],

    'security' => [
        'hsts_max_age' => env_get('HSTS_MAX_AGE', 31536000, 'int'),
        'enable_hsts' => env_get('ENABLE_HSTS', true, 'bool'),
        'admin_ip_allowlist' => env_get('ADMIN_IP_ALLOWLIST', [], 'array'),
        'bcrypt_cost' => 12,
        // Política de password
        'password_min_length' => 12,
        'password_require_upper' => true,
        'password_require_lower' => true,
        'password_require_digit' => true,
        'password_require_symbol' => true,
    ],

    'ratelimit' => [
        // [limit, window_seconds]
        'login_per_email' => [5, 900],     // 5 / 15 min
        'login_per_ip' => [20, 900],
        'refresh_per_ip' => [20, 60],
        'change_password' => [5, 900],
        'reset_password' => [10, 3600],
        'import' => [10, 3600],
        'general_per_user' => [120, 60],
    ],

    'cron' => [
        'token' => env_required('CRON_TOKEN'),
        'allowed_ips' => env_get('CRON_ALLOWED_IPS', ['127.0.0.1', '::1'], 'array'),
    ],

    'google_drive' => [
        'service_account_path' => env_get('GOOGLE_SERVICE_ACCOUNT_JSON_PATH', 'storage/credentials/service-account.json'),
        'root_folder_id' => env_get('GOOGLE_DRIVE_ROOT_FOLDER_ID', ''),
        'impersonate_user' => env_get('GOOGLE_IMPERSONATE_USER', ''),
        'allowed_mimes' => [
            'application/pdf',
            'image/png',
            'image/jpeg',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'max_file_size_bytes' => 25 * 1024 * 1024, // 25 MB
    ],

    'smtp' => [
        'host' => env_get('SMTP_HOST', ''),
        'port' => env_get('SMTP_PORT', 587, 'int'),
        'user' => env_get('SMTP_USER', ''),
        'pass' => env_get('SMTP_PASS', ''),
        'from' => env_get('SMTP_FROM', ''),
        'from_name' => env_get('SMTP_FROM_NAME', 'ITHub'),
        'encryption' => env_get('SMTP_ENCRYPTION', 'tls'),
    ],

    'notifications' => [
        'dias_previos' => env_get('NOTIF_DIAS_PREVIOS', [3, 1, 0], 'array'),
        'dias_vencida' => env_get('NOTIF_DIAS_VENCIDA', [1, 7, 15, 30], 'array'),
        'cc_emails' => env_get('NOTIF_CC_EMAILS', [], 'array'),
    ],

    'logging' => [
        'level' => env_get('LOG_LEVEL', 'info'),
        'retention_days' => env_get('LOG_RETENTION_DAYS', 90, 'int'),
        'path' => 'storage/logs',
    ],

    'storage' => [
        'logs' => 'storage/logs',
        'cache' => 'storage/cache',
        'exports' => 'storage/exports',
        'imports' => 'storage/imports',
        'ratelimit' => 'storage/ratelimit',
        'credentials' => 'storage/credentials',
    ],
];
