<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Models\RefreshToken;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuthService;
use ITHub\Api\Services\GoogleIdTokenVerifier;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Flujo completo de autenticación contra la base (SQLite en memoria):
 * login con Google (unico login), lockout, rotación de refresh con detección
 * de reuso y logout. Es lo que más importa que no se rompa.
 */
final class AuthServiceTest extends DatabaseTestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->container->get(AuthService::class);
    }

    /**
     * Abre una sesión con el login de Google (el único que existe) para el
     * email dado. El dominio permitido se toma del propio email.
     */
    private function loginOk(string $email): array
    {
        $dominio = substr($email, strpos($email, '@') + 1);
        $this->habilitarGoogle(['email' => $email, 'hd' => $dominio], $dominio);
        return $this->loginGoogle();
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
    // Login con Google
    // ------------------------------------------------------------

    /**
     * Habilita el login con Google en settings y reemplaza el verificador por
     * uno que devuelve el payload dado sin llamar a Google. Lo que se prueba
     * acá son las reglas de negocio sobre el payload, no la firma del token
     * (eso lo hace google/apiclient).
     *
     * @param array<string,mixed> $payload
     */
    private function habilitarGoogle(array $payload, string $dominio = 'intellihelp.tech'): void
    {
        $settings = $this->container->get('settings');
        $settings['google_login'] = ['client_id' => 'test-client-id', 'hosted_domain' => $dominio];
        $this->container->set('settings', $settings);

        $payload = array_merge([
            'sub' => '1234567890',
            'email' => 'nico@intellihelp.tech',
            'email_verified' => true,
            'hd' => 'intellihelp.tech',
            'name' => 'Nico',
        ], $payload);

        $this->container->set(GoogleIdTokenVerifier::class, new class($payload) extends GoogleIdTokenVerifier {
            /** @param array<string,mixed> $payload */
            public function __construct(private readonly array $payload)
            {
                parent::__construct('test-client-id');
            }

            public function verificar(string $idToken): array
            {
                if ($idToken === 'token-invalido') {
                    throw new AuthException('Token de Google inválido o vencido', 'GOOGLE_TOKEN_INVALID');
                }
                /** @var array{sub:string,email:string,email_verified:bool,hd:string,name:string} */
                return $this->payload;
            }
        });
    }

    private function loginGoogle(string $token = 'token-ok'): array
    {
        return $this->auth->loginConGoogle($token, $this->makeRequest(ip: '10.0.0.5'));
    }

    public function testGoogleDeshabilitadoSinClientId(): void
    {
        $this->crearUsuario(['email' => 'nico@intellihelp.tech']);

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_LOGIN_DISABLED', $code);
    }

    public function testGoogleOkAbreSesionYReseteaElContadorDeIntentos(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@intellihelp.tech', 'failed_login_attempts' => 3]);
        $this->habilitarGoogle([]);

        $res = $this->loginGoogle();

        self::assertSame($id, $res['user']->id);
        self::assertNotEmpty($res['access_token']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $res['refresh_token']);
        self::assertSame(1, RefreshToken::where('user_id', $id)->count());

        $user = User::find($id);
        self::assertSame(0, (int) $user->failed_login_attempts, 'el login OK resetea el contador');
        self::assertNotNull($user->last_login);
        self::assertSame('10.0.0.5', $user->last_login_ip);

        $audit = Capsule::table('auditoria')->where('accion', 'login')->first();
        self::assertNotNull($audit);
        self::assertStringContainsString('"via":"google"', (string) $audit->campos_modificados);
    }

    public function testGoogleElEmailDelTokenSeNormaliza(): void
    {
        $id = $this->crearUsuario(['email' => 'nico@intellihelp.tech']);
        $this->habilitarGoogle(['email' => '  Nico@IntelliHelp.tech ', 'hd' => 'IntelliHelp.tech']);

        // El verificador real ya normaliza; el fake devuelve el payload crudo,
        // así que acá se cubre la comparación case-insensitive de dominio y
        // la búsqueda del usuario en minúsculas que hace AuthService.
        $res = $this->loginGoogle();
        self::assertSame($id, $res['user']->id);
    }

    public function testGoogleRechazaTokenInvalido(): void
    {
        $this->crearUsuario(['email' => 'nico@intellihelp.tech']);
        $this->habilitarGoogle([]);

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle('token-invalido'));
        self::assertSame('GOOGLE_TOKEN_INVALID', $code);
    }

    public function testGoogleRechazaEmailNoVerificado(): void
    {
        $this->crearUsuario(['email' => 'nico@intellihelp.tech']);
        $this->habilitarGoogle(['email_verified' => false]);

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_EMAIL_UNVERIFIED', $code);
        self::assertSame(0, RefreshToken::count());
    }

    public function testGoogleRechazaCuentasDeOtroDominio(): void
    {
        // Gmail personal: sin `hd` y con otro sufijo de email
        $this->crearUsuario(['email' => 'nico@gmail.com']);
        $this->habilitarGoogle(['email' => 'nico@gmail.com', 'hd' => '']);

        [$code, $msg] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_DOMAIN', $code);
        self::assertStringContainsString('@intellihelp.tech', $msg);

        // Workspace de otro dominio, aunque el email exista en ITHub
        $this->crearUsuario(['email' => 'otro@otraempresa.com']);
        $this->habilitarGoogle(['email' => 'otro@otraempresa.com', 'hd' => 'otraempresa.com']);

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_DOMAIN', $code);

        // `hd` correcto pero el email no es del dominio (no debería pasar en
        // Google, pero no confiamos en un solo claim)
        $this->habilitarGoogle(['email' => 'nico@gmail.com', 'hd' => 'intellihelp.tech']);
        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_DOMAIN', $code);

        self::assertSame(0, RefreshToken::count());
        self::assertSame(3, Capsule::table('auditoria')->where('accion', 'login_fallido')->count());
    }

    public function testGoogleNoCreaUsuariosQueNoExisten(): void
    {
        $this->habilitarGoogle(['email' => 'nuevo@intellihelp.tech']);

        [$code, $msg] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_USER_NOT_FOUND', $code);
        self::assertStringContainsString('administrador', $msg);
        self::assertSame(0, User::count(), 'no se auto-registra');
    }

    public function testGoogleRespetaUsuarioInactivoYBloqueo(): void
    {
        $this->crearUsuario(['email' => 'nico@intellihelp.tech', 'activo' => 0]);
        $this->habilitarGoogle([]);

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('USER_INACTIVE', $code);

        User::where('email', 'nico@intellihelp.tech')->update([
            'activo' => 1,
            'locked_until' => date('Y-m-d H:i:s', time() + 600),
        ]);

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('ACCOUNT_LOCKED', $code);
        self::assertSame(0, RefreshToken::count());
    }

    public function testGoogleExigeDominioConfigurado(): void
    {
        $this->crearUsuario(['email' => 'nico@intellihelp.tech']);
        $this->habilitarGoogle([], dominio: '');

        [$code] = $this->esperarAuthException(fn () => $this->loginGoogle());
        self::assertSame('GOOGLE_LOGIN_DISABLED', $code);
    }
}
