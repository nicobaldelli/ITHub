<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Middleware\JwtAuthMiddleware;
use ITHub\Api\Models\IthubSesion;
use ITHub\Api\Models\RefreshToken;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuthService;
use ITHub\Api\Services\ItHubOidcClient;
use ITHub\Api\Tests\Support\DatabaseTestCase;
use ITHub\Api\Tests\Support\ItHubFalso;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Ingreso con ITHub contra la base: usuario y rol tomados del id_token,
 * sesión asociada al sid, aviso de cierre (back-channel logout) que corta
 * refresh y access, y convivencia con el login de Google.
 */
final class ItHubIngresoTest extends DatabaseTestCase
{
    private AuthService $auth;
    private ItHubFalso $ithub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ithub = new ItHubFalso(ItHubFalso::carpetaTemporal());
        $this->container->set(ItHubOidcClient::class, $this->ithub->cliente());
        $this->auth = $this->container->get(AuthService::class);
    }

    protected function tearDown(): void
    {
        ItHubFalso::borrarCarpeta($this->ithub->cacheDir);
        parent::tearDown();
    }

    /** Claims ya validados de un id_token de ITHub. */
    private function claims(array $override = []): array
    {
        return array_merge([
            'sub' => '12',
            'sid' => 'sid-abc',
            'email' => 'juan@intellihelp.tech',
            'email_verified' => true,
            'name' => 'Juan Gómez Pérez',
            'rol' => 'ventas',
        ], $override);
    }

    private function ingresar(array $override = []): array
    {
        return $this->auth->loginConItHub($this->claims($override), 'id.token.de-ithub', $this->makeRequest(ip: '10.0.0.9'));
    }

    private function codigoDeError(callable $fn): string
    {
        try {
            $fn();
        } catch (AuthException $e) {
            return $e->getErrorCode();
        }
        self::fail('Se esperaba AuthException');
    }

    /** Pasa un access token por JwtAuthMiddleware; devuelve el usuario o el código de error. */
    private function pasarMiddleware(string $access): string
    {
        $req = $this->makeRequest('GET', '/api/v1/auth/me')->withHeader('Authorization', 'Bearer ' . $access);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $r = new Response();
                $r->getBody()->write((string) $request->getAttribute('user')->email);
                return $r;
            }
        };
        try {
            return (string) (new JwtAuthMiddleware($this->container))->process($req, $handler)->getBody();
        } catch (AuthException $e) {
            return $e->getErrorCode();
        }
    }

    public function testPrimerIngresoCreaElUsuarioConElRolDeItHub(): void
    {
        $res = $this->ingresar();

        $user = User::where('email', 'juan@intellihelp.tech')->first();
        self::assertNotNull($user);
        self::assertSame($user->id, $res['user']->id);
        self::assertSame('ventas', $user->rol);
        self::assertTrue($user->activo);
        self::assertSame('Juan', $user->nombre);
        self::assertSame('Gómez Pérez', $user->apellido);
        self::assertSame('10.0.0.9', $user->last_login_ip);

        // Sesión asociada al sid de ITHub, con el id_token para salir
        $rt = RefreshToken::where('token_hash', hash('sha256', $res['refresh_token']))->first();
        $ses = IthubSesion::find($rt->family_id);
        self::assertNotNull($ses);
        self::assertSame('sid-abc', $ses->sid);
        self::assertSame('12', $ses->sub);
        self::assertSame('id.token.de-ithub', $ses->id_token);
        self::assertNull($ses->cerrada_en);

        // El access token de la API lleva el sid
        $jwt = $this->container->get('settings')['jwt'];
        $dec = JWT::decode($res['access_token'], new Key($jwt['secret'], $jwt['algo']));
        self::assertSame('sid-abc', $dec->sid);
        self::assertSame('ventas', $dec->rol);

        self::assertSame(1, Capsule::table('auditoria')->where('accion', 'login')->where('user_id', $user->id)->count());
    }

    public function testElRolDeItHubSeAplicaYReactivaAlUsuario(): void
    {
        $id = $this->crearUsuario(['email' => 'juan@intellihelp.tech', 'rol' => 'visualizador', 'activo' => 0,
            'nombre' => 'Juancito', 'apellido' => 'G']);

        $res = $this->ingresar(['rol' => 'cobranzas']);

        $user = User::find($id);
        self::assertSame($id, $res['user']->id);
        self::assertSame('cobranzas', $user->rol);
        self::assertTrue($user->activo);
        // Los datos cargados en Facturación no se pisan
        self::assertSame('Juancito', $user->nombre);
        self::assertSame(1, Capsule::table('auditoria')->where('accion', 'editar')->where('entidad_id', $id)->count());
    }

    public function testEmailSinDistinguirMayusculas(): void
    {
        $id = $this->crearUsuario(['email' => 'juan@intellihelp.tech', 'rol' => 'admin']);
        $res = $this->ingresar(['email' => 'Juan@IntelliHelp.tech', 'rol' => 'admin']);
        self::assertSame($id, $res['user']->id);
        self::assertSame(1, User::count());
    }

    public function testRolQueNoEsDeFacturacionNoEntra(): void
    {
        self::assertSame('ITHUB_ROL', $this->codigoDeError(fn () => $this->ingresar(['rol' => 'comercial'])));
        self::assertSame('ITHUB_ROL', $this->codigoDeError(fn () => $this->ingresar(['rol' => ''])));
        self::assertSame(0, User::count());
        self::assertSame(0, RefreshToken::count());
    }

    public function testSinSidOEmailNoEntra(): void
    {
        self::assertSame('ITHUB_CLAIMS', $this->codigoDeError(fn () => $this->ingresar(['sid' => ''])));
        self::assertSame('ITHUB_CLAIMS', $this->codigoDeError(fn () => $this->ingresar(['email' => ''])));
        self::assertSame('ITHUB_CLAIMS', $this->codigoDeError(fn () => $this->ingresar(['email_verified' => false])));
    }

    public function testRefreshConservaElSid(): void
    {
        $res = $this->ingresar();
        $nuevo = $this->auth->refresh($res['refresh_token'], $this->makeRequest());

        $jwt = $this->container->get('settings')['jwt'];
        self::assertSame('sid-abc', JWT::decode($nuevo['access_token'], new Key($jwt['secret'], $jwt['algo']))->sid);
        self::assertSame('juan@intellihelp.tech', $this->pasarMiddleware($nuevo['access_token']));
    }

    public function testAvisoDeCierreCortaRefreshYAccess(): void
    {
        $res = $this->ingresar();
        $otra = $this->ingresar(['sid' => 'sid-otra-sesion']);
        self::assertSame('juan@intellihelp.tech', $this->pasarMiddleware($res['access_token']));

        self::assertSame(1, $this->auth->cerrarSesionItHub('sid-abc', '12', $this->makeRequest()));

        // El access vigente deja de servir y el refresh no rota (ni se toma como robo)
        self::assertSame('ITHUB_SESION_CERRADA', $this->pasarMiddleware($res['access_token']));
        self::assertSame('ITHUB_SESION_CERRADA', $this->codigoDeError(
            fn () => $this->auth->refresh($res['refresh_token'], $this->makeRequest())
        ));
        self::assertSame(0, Capsule::table('auditoria')->where('accion', 'login_fallido')->count());

        // La otra sesión de ITHub (otro sid) sigue viva
        self::assertSame('juan@intellihelp.tech', $this->pasarMiddleware($otra['access_token']));
        $this->auth->refresh($otra['refresh_token'], $this->makeRequest());

        // Repetir el aviso no rompe nada
        self::assertSame(0, $this->auth->cerrarSesionItHub('sid-abc', '12', $this->makeRequest()));
    }

    public function testAvisoDeCierreSinSidCierraTodasLasDeLaPersona(): void
    {
        $a = $this->ingresar();
        $b = $this->ingresar(['sid' => 'sid-2']);
        $otro = $this->ingresar(['sub' => '99', 'sid' => 'sid-3', 'email' => 'otro@intellihelp.tech']);

        self::assertSame(2, $this->auth->cerrarSesionItHub('', '12', $this->makeRequest()));
        self::assertSame('ITHUB_SESION_CERRADA', $this->pasarMiddleware($a['access_token']));
        self::assertSame('ITHUB_SESION_CERRADA', $this->pasarMiddleware($b['access_token']));
        self::assertSame('otro@intellihelp.tech', $this->pasarMiddleware($otro['access_token']));
    }

    public function testLogoutDevuelveElIdTokenParaSalirDeItHub(): void
    {
        $res = $this->ingresar();
        self::assertSame('id.token.de-ithub', $this->auth->logout($res['refresh_token'], $this->makeRequest()));
        self::assertNull($this->auth->logout('refresh-que-no-existe', $this->makeRequest()));
    }

    public function testConItHubNoSeEntraPorGoogleNiSeRenuevanSesionesViejas(): void
    {
        $id = $this->crearUsuario(['email' => 'juan@intellihelp.tech']);
        // Sesión abierta por Google antes de configurar ITHub
        $viejo = bin2hex(random_bytes(32));
        Capsule::table('refresh_tokens')->insert([
            'user_id' => $id,
            'token_hash' => hash('sha256', $viejo),
            'family_id' => '00000000-0000-4000-8000-000000000001',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => $this->now(),
        ]);

        self::assertSame('GOOGLE_LOGIN_DISABLED', $this->codigoDeError(
            fn () => $this->auth->loginConGoogle('token-google', $this->makeRequest())
        ));
        self::assertSame('ITHUB_REQUERIDO', $this->codigoDeError(
            fn () => $this->auth->refresh($viejo, $this->makeRequest())
        ));
    }

    public function testSinItHubLasSesionesDeGoogleSiguenComoSiempre(): void
    {
        $this->container->set(ItHubOidcClient::class, new ItHubOidcClient([], $this->ithub->cacheDir));
        $id = $this->crearUsuario(['email' => 'juan@intellihelp.tech']);
        $raw = bin2hex(random_bytes(32));
        Capsule::table('refresh_tokens')->insert([
            'user_id' => $id,
            'token_hash' => hash('sha256', $raw),
            'family_id' => '00000000-0000-4000-8000-000000000002',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => $this->now(),
        ]);

        $res = $this->auth->refresh($raw, $this->makeRequest());
        self::assertSame($id, $res['user']->id);
        self::assertNull($this->auth->logout($res['refresh_token'], $this->makeRequest()));
    }
}
