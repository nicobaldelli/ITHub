<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\RefreshToken;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuthService;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Flujo completo de autenticación contra la base (SQLite en memoria):
 * login, lockout, rotación de refresh con detección de reuso, logout y
 * cambio de password. Es lo que más importa que no se rompa.
 */
final class AuthServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'Password-Segura-1!';

    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->container->get(AuthService::class);
    }

    private function loginOk(string $email): array
    {
        return $this->auth->login($email, self::PASSWORD, $this->makeRequest(ip: '10.0.0.5'));
    }

    /** @return array{0:string,1:string} [errorCode, message] */
    private function esperarAuthException(callable $fn): array
    {
        try {
            $fn();
        } catch (AuthException $e) {
            return [$e->getErrorCode(), $e->getMessage()];
        }
        self::fail('Se esperaba AuthException');
    }

    // ------------------------------------------------------------
    // Login
    // ------------------------------------------------------------

    public function testLoginOkDevuelveTokensYPersisteElRefreshHasheado(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local', 'rol' => 'admin']);

        $res = $this->loginOk('nico@test.local');

        self::assertSame($id, $res['user']->id);
        self::assertNotEmpty($res['access_token']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $res['refresh_token']);
        self::assertGreaterThan(time(), $res['access_expires_at']);
        self::assertGreaterThan($res['access_expires_at'], $res['refresh_expires_at']);

        // El refresh se guarda como SHA-256, nunca en claro
        $rt = RefreshToken::where('user_id', $id)->first();
        self::assertNotNull($rt);
        self::assertSame(hash('sha256', $res['refresh_token']), $rt->token_hash);
        self::assertNull($rt->revoked_at);
        self::assertSame('10.0.0.5', $rt->ip);
        self::assertSame('phpunit', $rt->user_agent);

        // last_login e IP quedan registrados, y hay auditoría de login
        $user = User::find($id);
        self::assertNotNull($user->last_login);
        self::assertSame('10.0.0.5', $user->last_login_ip);
        self::assertSame(1, Capsule::table('auditoria')->where('user_id', $id)->where('accion', 'login')->count());
    }

    public function testElEmailNoDistingueMayusculasNiEspacios(): void
    {
        $this->crearUsuario(['email' => 'nico@test.local']);
        $res = $this->auth->login('  Nico@Test.LOCAL ', self::PASSWORD, $this->makeRequest());
        self::assertSame('nico@test.local', $res['user']->email);
    }

    public function testPasswordIncorrectaIncrementaIntentosYNoRevelaNada(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);

        [$code, $msg] = $this->esperarAuthException(
            fn () => $this->auth->login('nico@test.local', 'incorrecta', $this->makeRequest())
        );

        self::assertSame('INVALID_CREDENTIALS', $code);
        self::assertSame(1, User::find($id)->failed_login_attempts);
        self::assertSame(1, Capsule::table('auditoria')->where('accion', 'login_fallido')->where('user_id', $id)->count());

        // Mismo mensaje para email inexistente: no se revela si existe la cuenta
        [$code2, $msg2] = $this->esperarAuthException(
            fn () => $this->auth->login('nadie@test.local', 'x', $this->makeRequest())
        );
        self::assertSame('INVALID_CREDENTIALS', $code2);
        self::assertSame($msg, $msg2);

        // Y la auditoría del email desconocido no guarda el email en claro
        $fila = Capsule::table('auditoria')->where('accion', 'login_fallido')->whereNull('user_id')->first();
        self::assertNotNull($fila);
        self::assertStringNotContainsString('nadie@test.local', (string) $fila->campos_modificados);
        self::assertStringContainsString('email_hash', (string) $fila->campos_modificados);
    }

    public function testCincoIntentosFallidosBloqueanLaCuentaAunConPasswordCorrecta(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);

        for ($i = 0; $i < 5; $i++) {
            $this->esperarAuthException(
                fn () => $this->auth->login('nico@test.local', 'incorrecta', $this->makeRequest())
            );
        }

        $user = User::find($id);
        self::assertSame(5, $user->failed_login_attempts);
        self::assertNotNull($user->locked_until);
        self::assertTrue($user->isLocked());

        [$code] = $this->esperarAuthException(fn () => $this->loginOk('nico@test.local'));
        self::assertSame('ACCOUNT_LOCKED', $code);
    }

    public function testCuatroIntentosFallidosNoBloqueanYElLoginOkReseteaElContador(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);

        for ($i = 0; $i < 4; $i++) {
            $this->esperarAuthException(
                fn () => $this->auth->login('nico@test.local', 'incorrecta', $this->makeRequest())
            );
        }
        self::assertFalse(User::find($id)->isLocked());

        $this->loginOk('nico@test.local');
        $user = User::find($id);
        self::assertSame(0, $user->failed_login_attempts);
        self::assertNull($user->locked_until);
    }

    public function testElBloqueoVenceSolo(): void
    {
        $id = $this->crearUsuario([
            'email' => 'nico@test.local',
            'failed_login_attempts' => 5,
            'locked_until' => date('Y-m-d H:i:s', time() - 60), // venció hace un minuto
        ]);

        $res = $this->loginOk('nico@test.local');
        self::assertSame($id, $res['user']->id);
    }

    public function testUsuarioInactivoNoPuedeLoguearse(): void
    {
        $this->crearUsuario(['email' => 'nico@test.local', 'activo' => 0]);
        [$code] = $this->esperarAuthException(fn () => $this->loginOk('nico@test.local'));
        self::assertSame('USER_INACTIVE', $code);
    }

    // ------------------------------------------------------------
    // Refresh rotation
    // ------------------------------------------------------------

    public function testRefreshRotaElTokenYRevocaElAnteriorEnLaMismaFamilia(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);
        $login = $this->loginOk('nico@test.local');

        $res = $this->auth->refresh($login['refresh_token'], $this->makeRequest());

        self::assertSame($id, $res['user']->id);
        self::assertNotSame($login['refresh_token'], $res['refresh_token']);
        self::assertNotSame($login['access_token'], $res['access_token']);

        $viejo = RefreshToken::where('token_hash', hash('sha256', $login['refresh_token']))->first();
        $nuevo = RefreshToken::where('token_hash', hash('sha256', $res['refresh_token']))->first();

        self::assertNotNull($viejo->revoked_at, 'el token usado queda revocado');
        self::assertSame($nuevo->id, $viejo->replaced_by_id);
        self::assertNull($nuevo->revoked_at);
        self::assertSame($viejo->family_id, $nuevo->family_id, 'misma familia');
    }

    public function testReusarUnRefreshYaRotadoRevocaTodaLaFamilia(): void
    {
        $this->crearUsuario(['email' => 'nico@test.local']);
        $login = $this->loginOk('nico@test.local');
        $rotado = $this->auth->refresh($login['refresh_token'], $this->makeRequest());

        // Un atacante (o un doble refresh) presenta el token viejo
        [$code] = $this->esperarAuthException(
            fn () => $this->auth->refresh($login['refresh_token'], $this->makeRequest())
        );
        self::assertSame('REFRESH_REUSED', $code);

        // Y el token nuevo, que era legítimo, también queda inutilizado
        $nuevo = RefreshToken::where('token_hash', hash('sha256', $rotado['refresh_token']))->first();
        self::assertNotNull($nuevo->revoked_at);
        self::assertSame(0, RefreshToken::whereNull('revoked_at')->count(), 'ninguna sesión de la familia sobrevive');

        [$code2] = $this->esperarAuthException(
            fn () => $this->auth->refresh($rotado['refresh_token'], $this->makeRequest())
        );
        self::assertSame('REFRESH_REUSED', $code2);
    }

    public function testElReusoNoAfectaOtrasFamiliasDelMismoUsuario(): void
    {
        $this->crearUsuario(['email' => 'nico@test.local']);
        $sesionA = $this->loginOk('nico@test.local'); // ej: la PC
        $sesionB = $this->loginOk('nico@test.local'); // ej: el celular

        $rotadoA = $this->auth->refresh($sesionA['refresh_token'], $this->makeRequest());
        $this->esperarAuthException(fn () => $this->auth->refresh($sesionA['refresh_token'], $this->makeRequest()));

        // La sesión B sigue viva
        $resB = $this->auth->refresh($sesionB['refresh_token'], $this->makeRequest());
        self::assertNotEmpty($resB['access_token']);
        unset($rotadoA);
    }

    public function testRefreshInvalidoYExpirado(): void
    {
        $this->crearUsuario(['email' => 'nico@test.local']);
        $login = $this->loginOk('nico@test.local');

        [$code] = $this->esperarAuthException(
            fn () => $this->auth->refresh(str_repeat('0', 64), $this->makeRequest())
        );
        self::assertSame('REFRESH_INVALID', $code);

        Capsule::table('refresh_tokens')->update(['expires_at' => date('Y-m-d H:i:s', time() - 10)]);
        [$code2] = $this->esperarAuthException(
            fn () => $this->auth->refresh($login['refresh_token'], $this->makeRequest())
        );
        self::assertSame('REFRESH_EXPIRED', $code2);
    }

    public function testRefreshDeUsuarioDesactivadoFalla(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);
        $login = $this->loginOk('nico@test.local');
        Capsule::table('users')->where('id', $id)->update(['activo' => 0]);

        [$code] = $this->esperarAuthException(
            fn () => $this->auth->refresh($login['refresh_token'], $this->makeRequest())
        );
        self::assertSame('USER_INACTIVE', $code);
    }

    // ------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------

    public function testLogoutRevocaSoloEseRefresh(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);
        $a = $this->loginOk('nico@test.local');
        $b = $this->loginOk('nico@test.local');

        $this->auth->logout($a['refresh_token'], $this->makeRequest());

        self::assertNotNull(RefreshToken::where('token_hash', hash('sha256', $a['refresh_token']))->first()->revoked_at);
        self::assertNull(RefreshToken::where('token_hash', hash('sha256', $b['refresh_token']))->first()->revoked_at);
        self::assertSame(1, Capsule::table('auditoria')->where('user_id', $id)->where('accion', 'logout')->count());

        // Logout con un token desconocido no explota ni audita
        $this->auth->logout('no-existe', $this->makeRequest());
        self::assertSame(1, Capsule::table('auditoria')->where('accion', 'logout')->count());
    }

    public function testLogoutAllRevocaTodasLasSesionesDelUsuario(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);
        $this->loginOk('nico@test.local');
        $this->loginOk('nico@test.local');
        $otro = $this->crearUsuario(['email' => 'otro@test.local']);
        $this->loginOk('otro@test.local');

        $count = $this->auth->logoutAll(User::find($id), $this->makeRequest());

        self::assertSame(2, $count);
        self::assertSame(0, RefreshToken::where('user_id', $id)->whereNull('revoked_at')->count());
        self::assertSame(1, RefreshToken::where('user_id', $otro)->whereNull('revoked_at')->count(), 'no toca a otros usuarios');
    }

    // ------------------------------------------------------------
    // Cambio de password
    // ------------------------------------------------------------

    public function testChangePasswordExigeLaActualSalvoCambioForzado(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);
        $user = User::find($id);

        [$code] = $this->esperarAuthException(
            fn () => $this->auth->changePassword($user, 'incorrecta', 'Nueva-Password-9!', $this->makeRequest())
        );
        self::assertSame('INVALID_CURRENT_PASSWORD', $code);

        // Con must_change_password no hace falta la actual
        $forzadoId = $this->crearUsuario(['email' => 'nuevo@test.local', 'must_change_password' => 1]);
        $forzado = User::find($forzadoId);
        $this->auth->changePassword($forzado, null, 'Nueva-Password-9!', $this->makeRequest());

        $forzado = User::find($forzadoId);
        self::assertFalse($forzado->must_change_password);
        self::assertTrue(password_verify('Nueva-Password-9!', $forzado->password_hash));
    }

    public function testChangePasswordRevocaLasSesionesYRechazaRepetirLaAnterior(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@test.local']);
        $this->loginOk('nico@test.local');
        $this->loginOk('nico@test.local');
        $user = User::find($id);

        try {
            $this->auth->changePassword($user, self::PASSWORD, self::PASSWORD, $this->makeRequest());
            self::fail('no debe permitir reusar la misma password');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('new_password', $e->getDetails());
        }

        $this->auth->changePassword($user, self::PASSWORD, 'Otra-Password-2024!', $this->makeRequest());

        self::assertSame(0, RefreshToken::where('user_id', $id)->whereNull('revoked_at')->count(), 'fuerza relogin en todos los dispositivos');
        self::assertSame(1, Capsule::table('auditoria')->where('user_id', $id)->where('accion', 'cambio_password')->count());

        // La password nueva sirve para loguearse; la vieja no
        $this->auth->login('nico@test.local', 'Otra-Password-2024!', $this->makeRequest());
        [$code] = $this->esperarAuthException(fn () => $this->loginOk('nico@test.local'));
        self::assertSame('INVALID_CREDENTIALS', $code);
    }

    public function testPoliticaDeComplejidadDePassword(): void
    {
        $casos = [
            'corta' => 'Ab1!',
            'sin mayuscula' => 'password-segura-1!',
            'sin minuscula' => 'PASSWORD-SEGURA-1!',
            'sin digito' => 'Password-Segura-!!',
            'sin simbolo' => 'PasswordSegura123',
        ];
        foreach ($casos as $caso => $pwd) {
            try {
                $this->auth->validatePasswordStrength($pwd);
                self::fail("Debería rechazar: {$caso}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $this->auth->validatePasswordStrength('Password-Segura-1!');
        self::assertTrue(true, 'una password que cumple todo no lanza');
    }
}
