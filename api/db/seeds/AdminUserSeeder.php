<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Crea el usuario admin inicial.
 * El unico login es con Google Workspace, asi que SEED_ADMIN_EMAIL tiene que ser
 * una cuenta del dominio (ej: nbaldelli@intellihelp.tech). No se genera password
 * utilizable: password_hash lleva un valor aleatorio solo porque la columna es NOT NULL.
 */
final class AdminUserSeeder extends AbstractSeed
{
    public function run(): void
    {
        // Si ya existe un admin, no hacemos nada (idempotente)
        $exists = $this->fetchRow("SELECT id FROM users WHERE rol = 'admin' LIMIT 1");
        if ($exists) {
            echo "[seed] Ya existe al menos un admin. No se crea otro.\n";
            return;
        }

        // Se aceptan por variable de entorno del shell (SEED_ADMIN_EMAIL=... phinx seed:run)
        // o desde el .env (phpdotenv llena $_ENV, no getenv()).
        $email = $this->envOr('SEED_ADMIN_EMAIL', 'admin@intellihelp.tech');
        $nombre = $this->envOr('SEED_ADMIN_NOMBRE', 'Admin');
        $apellido = $this->envOr('SEED_ADMIN_APELLIDO', 'ITHub');

        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);

        $now = date('Y-m-d H:i:s');

        $this->table('users')->insert([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'password_hash' => $hash,
            'rol' => 'admin',
            'activo' => 1,
            'must_change_password' => 0,
            'failed_login_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->save();

        echo "[seed] Admin creado: {$email}. Entra con 'Iniciar sesion con Google'.\n";
    }

    private function envOr(string $key, string $default): string
    {
        $fromEnv = $_ENV[$key] ?? getenv($key);
        return is_string($fromEnv) && trim($fromEnv) !== '' ? trim($fromEnv) : $default;
    }
}
