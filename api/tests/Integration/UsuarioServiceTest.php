<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;
use ITHub\Api\Services\UsuarioService;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Alta y edición de usuarios con login solo por Google: el email tiene que
 * ser del dominio de Workspace y no se genera ni se devuelve password.
 */
final class UsuarioServiceTest extends DatabaseTestCase
{
    private UsuarioService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->container->get('settings');
        $settings['google_login'] = ['client_id' => 'test-client-id', 'hosted_domain' => 'intellihelp.tech'];
        $this->container->set('settings', $settings);

        $this->service = $this->container->get(UsuarioService::class);
        $this->admin = User::findOrFail($this->crearUsuario(['email' => 'admin@intellihelp.tech']));
    }

    public function testAltaConEmailDelDominioNoDevuelvePasswordNiFuerzaCambio(): void
    {
        $u = $this->service->create(
            ['nombre' => 'Brian', 'apellido' => 'Sierra', 'email' => 'BSierra@IntelliHelp.tech', 'rol' => 'admin'],
            $this->admin,
            $this->makeRequest()
        );

        self::assertInstanceOf(User::class, $u);
        self::assertSame('bsierra@intellihelp.tech', $u->email);
        self::assertSame('admin', $u->rol);
        self::assertFalse((bool) $u->must_change_password);
        self::assertArrayNotHasKey('password_hash', $u->toArray(), 'el hash no se serializa');
        // Hay hash (la columna es NOT NULL) pero es de un valor aleatorio
        self::assertNotEmpty($u->password_hash);
    }

    public function testAltaIgnoraPasswordDelPayload(): void
    {
        $u = $this->service->create(
            ['nombre' => 'Ana', 'apellido' => 'Test', 'email' => 'ana@intellihelp.tech', 'rol' => 'ventas', 'password' => 'Conocida-1234!'],
            $this->admin,
            $this->makeRequest()
        );

        self::assertFalse(password_verify('Conocida-1234!', $u->password_hash));
    }

    public function testAltaRechazaEmailDeOtroDominio(): void
    {
        foreach (['ana@gmail.com', 'ana@otraempresa.com', 'ana@intellihelp.tech.evil.com'] as $email) {
            try {
                $this->service->create(
                    ['nombre' => 'Ana', 'apellido' => 'Test', 'email' => $email, 'rol' => 'ventas'],
                    $this->admin,
                    $this->makeRequest()
                );
                self::fail("Se esperaba rechazo para {$email}");
            } catch (ValidationException $e) {
                self::assertSame('dominio', $e->getDetails()['email'] ?? null, $email);
            }
        }
        self::assertSame(1, User::count(), 'solo queda el admin del setUp');
    }

    public function testEdicionRechazaCambiarElEmailAOtroDominio(): void
    {
        $id = $this->crearUsuario(['email' => 'ana@intellihelp.tech', 'rol' => 'ventas']);

        $this->expectException(ValidationException::class);
        $this->service->update($id, ['email' => 'ana@gmail.com'], $this->admin, $this->makeRequest());
    }
}
