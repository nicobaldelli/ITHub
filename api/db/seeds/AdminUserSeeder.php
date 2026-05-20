<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Crea el usuario admin inicial.
 * Password generado random; se imprime UNA SOLA VEZ por stdout para que lo guardes.
 * Marca must_change_password = true para forzar rotación al primer login.
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

        $email = getenv('SEED_ADMIN_EMAIL') ?: 'admin@intellihelp.tech';
        $nombre = getenv('SEED_ADMIN_NOMBRE') ?: 'Admin';
        $apellido = getenv('SEED_ADMIN_APELLIDO') ?: 'ITHub';

        // Password aleatorio fuerte
        $password = $this->generateStrongPassword(16);
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $now = date('Y-m-d H:i:s');

        $this->table('users')->insert([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'password_hash' => $hash,
            'rol' => 'admin',
            'activo' => 1,
            'must_change_password' => 1,
            'failed_login_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->save();

        echo "\n";
        echo str_repeat('=', 70) . "\n";
        echo "  USUARIO ADMIN CREADO (GUARDAR CREDENCIALES, NO SE MUESTRAN OTRA VEZ)\n";
        echo str_repeat('=', 70) . "\n";
        echo "  Email   : {$email}\n";
        echo "  Password: {$password}\n";
        echo str_repeat('=', 70) . "\n";
        echo "  ATENCIÓN: must_change_password=true -> se forzará el cambio al primer login.\n";
        echo str_repeat('=', 70) . "\n\n";
    }

    private function generateStrongPassword(int $length = 16): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digit = '23456789';
        $sym = '!@#$%^&*()-_=+[]{}<>?';
        $all = $upper . $lower . $digit . $sym;

        // Asegurar al menos 1 de cada clase
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digit[random_int(0, strlen($digit) - 1)],
            $sym[random_int(0, strlen($sym) - 1)],
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);
        return implode('', $chars);
    }
}
