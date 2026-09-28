<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Exceptions\AuthException;
use ITHub\Api\Middleware\MustChangePasswordMiddleware;
use ITHub\Api\Models\User;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MustChangePasswordMiddlewareTest extends TestCase
{
    private function handlerQueDevuelve200(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public bool $llamado = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->llamado = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    private function requestConUsuario(?bool $mustChange): ServerRequestInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/clientes');
        if ($mustChange === null) {
            return $req;
        }
        $user = new User();
        $user->must_change_password = $mustChange;
        return $req->withAttribute('user', $user);
    }

    public function testBloqueaConMustChangePasswordActivo(): void
    {
        $handler = $this->handlerQueDevuelve200();

        try {
            (new MustChangePasswordMiddleware())->process($this->requestConUsuario(true), $handler);
            self::fail('Debería lanzar AuthException');
        } catch (AuthException $e) {
            self::assertSame('MUST_CHANGE_PASSWORD', $e->getErrorCode());
            self::assertSame(401, $e->getStatusCode());
        }

        self::assertFalse($handler->llamado, 'la ruta no debe ejecutarse');
    }

    public function testDejaPasarConPasswordYaCambiada(): void
    {
        $handler = $this->handlerQueDevuelve200();
        $res = (new MustChangePasswordMiddleware())->process($this->requestConUsuario(false), $handler);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($handler->llamado);
    }

    public function testDejaPasarSiNoHayUsuarioEnElRequest(): void
    {
        // No es su responsabilidad autenticar: eso lo hace JwtAuthMiddleware antes
        $handler = $this->handlerQueDevuelve200();
        $res = (new MustChangePasswordMiddleware())->process($this->requestConUsuario(null), $handler);

        self::assertSame(200, $res->getStatusCode());
    }
}
