<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\User;
use ITHub\Api\Validators\UsuarioValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsuarioValidatorTest extends TestCase
{
    private const BASE = [
        'nombre' => 'Juan',
        'apellido' => 'Pérez',
        'email' => 'juan.perez@intellihelp.tech',
        'rol' => 'ventas',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function detalles(array $data, bool $isUpdate = false): array
    {
        try {
            UsuarioValidator::validate($data, $isUpdate);
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    // ------------------------------------------------------------
    // Create
    // ------------------------------------------------------------

    public function testCreateValidoDevuelveElPayloadNormalizado(): void
    {
        $clean = UsuarioValidator::validate([
            'nombre' => '  Juan ',
            'apellido' => ' Pérez  ',
            'email' => '  Juan.Perez@IntelliHelp.TECH ',
            'rol' => 'ventas',
        ]);

        self::assertSame([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'email' => 'juan.perez@intellihelp.tech',
            'rol' => 'ventas',
        ], $clean);
    }

    public function testCreateVacioExigeNombreApellidoEmailYRol(): void
    {
        $details = self::detalles([]);
        self::assertSame(['nombre', 'apellido', 'email', 'rol'], array_keys($details));
    }

    public function testLanzaValidationExceptionCon422(): void
    {
        try {
            UsuarioValidator::validate([]);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
            self::assertSame('Datos inválidos', $e->getMessage());
        }
    }

    public function testNombreYApellidoTienenMaximo100Caracteres(): void
    {
        self::assertSame('Requerido, hasta 100 caracteres', self::detalles(['nombre' => str_repeat('a', 101)] + self::BASE)['nombre']);
        self::assertSame('Requerido, hasta 100 caracteres', self::detalles(['apellido' => str_repeat('a', 101)] + self::BASE)['apellido']);

        $clean = UsuarioValidator::validate(['nombre' => str_repeat('a', 100), 'apellido' => str_repeat('b', 100)] + self::BASE);
        self::assertSame(100, mb_strlen($clean['nombre']));
        self::assertSame(100, mb_strlen($clean['apellido']));
    }

    public function testNombreOApellidoSoloConEspaciosSonRechazados(): void
    {
        self::assertArrayHasKey('nombre', self::detalles(['nombre' => '   '] + self::BASE));
        self::assertArrayHasKey('apellido', self::detalles(['apellido' => "\t"] + self::BASE));
    }

    // ------------------------------------------------------------
    // Email
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function emailsInvalidos(): array
    {
        return [
            'sin arroba' => ['juan.intellihelp.tech'],
            'sin dominio' => ['juan@'],
            'con espacios internos' => ['ju an@intellihelp.tech'],
            'vacio' => [''],
            'doble arroba' => ['a@@b.com'],
        ];
    }

    #[DataProvider('emailsInvalidos')]
    public function testEmailInvalidoEsRechazado(string $email): void
    {
        self::assertSame('Email inválido', self::detalles(['email' => $email] + self::BASE)['email']);
    }

    public function testEmailDeMasDe150CaracteresEsRechazado(): void
    {
        $email = str_repeat('a', 140) . '@example.com'; // 152 caracteres
        self::assertArrayHasKey('email', self::detalles(['email' => $email] + self::BASE));
    }

    public function testEmailSePasaAMinusculas(): void
    {
        self::assertSame('admin@example.com', UsuarioValidator::validate(['email' => 'ADMIN@Example.COM'] + self::BASE)['email']);
    }

    // ------------------------------------------------------------
    // Rol
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function rolesValidos(): array
    {
        return [
            'admin' => ['admin'],
            'cobranzas' => ['cobranzas'],
            'ventas' => ['ventas'],
            'visualizador' => ['visualizador'],
        ];
    }

    #[DataProvider('rolesValidos')]
    public function testAceptaLosCuatroRoles(string $rol): void
    {
        self::assertContains($rol, User::ROLES);
        self::assertSame($rol, UsuarioValidator::validate(['rol' => $rol] + self::BASE)['rol']);
    }

    public function testLosRolesDelModeloSonExactamenteCuatro(): void
    {
        self::assertSame(['admin', 'cobranzas', 'ventas', 'visualizador'], User::ROLES);
    }

    /** @return array<string, array{mixed}> */
    public static function rolesInvalidos(): array
    {
        return [
            'superadmin' => ['superadmin'],
            'mayusculas' => ['Admin'],
            'vacio' => [''],
            'null' => [null],
            'numero' => [1],
        ];
    }

    #[DataProvider('rolesInvalidos')]
    public function testRechazaRolesFueraDeLaLista(mixed $rol): void
    {
        self::assertSame('Permitidos: admin, cobranzas, ventas, visualizador', self::detalles(['rol' => $rol] + self::BASE)['rol']);
    }

    // ------------------------------------------------------------
    // Update parcial
    // ------------------------------------------------------------

    public function testUpdateAceptaPayloadParcial(): void
    {
        self::assertSame(['rol' => 'cobranzas'], UsuarioValidator::validate(['rol' => 'cobranzas'], true));
        self::assertSame(['activo' => false], UsuarioValidator::validate(['activo' => 0], true));
    }

    public function testUpdateVacioDevuelveArrayVacio(): void
    {
        self::assertSame([], UsuarioValidator::validate([], true));
    }

    public function testUpdateValidaSoloLasClavesPresentes(): void
    {
        self::assertSame(['nombre'], array_keys(self::detalles(['nombre' => ''], true)));
        self::assertSame(['email'], array_keys(self::detalles(['email' => 'nope'], true)));
        self::assertSame(['rol'], array_keys(self::detalles(['rol' => 'root'], true)));
    }

    // ------------------------------------------------------------
    // Password / whitelist
    // ------------------------------------------------------------

    public function testPasswordNoSeValidaNiPasaAlResultado(): void
    {
        // La complejidad de password se delega a AuthService::validatePasswordStrength;
        // el validador ni la revisa ni la deja pasar al payload limpio.
        $clean = UsuarioValidator::validate(['password' => '123', 'password_hash' => 'hash'] + self::BASE);

        self::assertArrayNotHasKey('password', $clean);
        self::assertArrayNotHasKey('password_hash', $clean);
    }

    public function testClavesFueraDeLaWhitelistNoPasanAlResultado(): void
    {
        $clean = UsuarioValidator::validate([
            'id' => 1,
            'must_change_password' => false,
            'failed_login_attempts' => 0,
            'created_at' => '2026-01-01',
        ] + self::BASE);

        self::assertSame(['nombre', 'apellido', 'email', 'rol'], array_keys($clean));
    }

    public function testActivoSeCasteaABool(): void
    {
        self::assertTrue(UsuarioValidator::validate(['activo' => 1] + self::BASE)['activo']);
        self::assertTrue(UsuarioValidator::validate(['activo' => 'si'] + self::BASE)['activo']);
        self::assertFalse(UsuarioValidator::validate(['activo' => '0'] + self::BASE)['activo']);
        self::assertFalse(UsuarioValidator::validate(['activo' => ''] + self::BASE)['activo']);
    }
}
