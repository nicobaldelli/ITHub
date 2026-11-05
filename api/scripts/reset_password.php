<?php

/**
 * Resetea la password de un usuario desde la consola (sin pasar por la API).
 * Para cuando el admin se queda afuera: password olvidada, cuenta bloqueada,
 * seed cuya salida no se guardó.
 *
 * Uso:
 *   php scripts/reset_password.php <email>              -> genera una password temporal y la imprime
 *   php scripts/reset_password.php <email> --activar    -> ademas reactiva el usuario si estaba inactivo
 *
 * En Docker (local):
 *   docker compose exec api php scripts/reset_password.php admin@intellihelp.tech
 * En Hostinger:
 *   <PHP_BIN> ~/domains/apithub.intellihelp.tech/app/api/scripts/reset_password.php admin@intellihelp.tech
 *
 * Efectos: nuevo hash bcrypt (cost 12), must_change_password=1 (se fuerza
 * el cambio al entrar), se desbloquea la cuenta (intentos fallidos y
 * locked_until en cero) y se revocan todas las sesiones del usuario.
 * La password temporal se imprime UNA sola vez; no queda en ningún log.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ITHub\Api\Bootstrap\App;
use ITHub\Api\Models\RefreshToken;
use ITHub\Api\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

$email = strtolower(trim((string) ($argv[1] ?? '')));
$activar = in_array('--activar', $argv, true);

if ($email === '' || str_starts_with($email, '--')) {
    fwrite(STDERR, "Uso: php scripts/reset_password.php <email> [--activar]\n");
    exit(1);
}

try {
    $slim = (new App(dirname(__DIR__)))->build();
    $slim->getContainer()?->get(Capsule::class);

    $user = User::where('email', $email)->first();
    if ($user === null) {
        fwrite(STDERR, "No existe ningún usuario con email {$email}.\n");
        $emails = User::query()->orderBy('id')->pluck('email')->all();
        fwrite(STDERR, "Usuarios cargados: " . ($emails === [] ? '(ninguno, corré el seed)' : implode(', ', $emails)) . "\n");
        exit(1);
    }

    // Misma politica que el seed: 16 chars con mayuscula, minuscula, digito y simbolo
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digit = '23456789';
    $sym = '!@#$%^&*()-_=+[]{}<>?';
    $all = $upper . $lower . $digit . $sym;
    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digit[random_int(0, strlen($digit) - 1)],
        $sym[random_int(0, strlen($sym) - 1)],
    ];
    for ($i = count($chars); $i < 16; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);
    $password = implode('', $chars);

    $user->password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $user->must_change_password = true;
    $user->failed_login_attempts = 0;
    $user->locked_until = null;
    if ($activar) {
        $user->activo = true;
    }
    $user->save();

    $revocados = RefreshToken::where('user_id', $user->id)
        ->whereNull('revoked_at')
        ->update(['revoked_at' => date('Y-m-d H:i:s')]);

    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  PASSWORD TEMPORAL RESETEADA (no se vuelve a mostrar)\n";
    echo str_repeat('=', 70) . "\n";
    echo "  Email   : {$user->email}\n";
    echo "  Password: {$password}\n";
    echo "  Rol     : {$user->rol}" . ($user->activo ? '' : '  (INACTIVO: usá --activar)') . "\n";
    echo str_repeat('=', 70) . "\n";
    echo "  Al entrar se pide cambiarla. Sesiones revocadas: {$revocados}.\n\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
