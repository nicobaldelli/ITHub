<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Validators\ClienteValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClienteValidatorTest extends TestCase
{
    /** Payload mínimo válido para create. */
    private const BASE = [
        'razon_social' => 'Acme SA',
        'cuit' => '20-12345678-6',
    ];

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function detalles(array $data, bool $isUpdate = false): array
    {
        try {
            ClienteValidator::validate($data, $isUpdate);
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    // ------------------------------------------------------------
    // Create
    // ------------------------------------------------------------

    public function testCreateValidoDevuelveRazonSocialYCuitNormalizados(): void
    {
        $clean = ClienteValidator::validate(['razon_social' => '  Acme SA  ', 'cuit' => '20123456786']);

        self::assertSame(['razon_social' => 'Acme SA', 'cuit' => '20-12345678-6'], $clean);
    }

    public function testCreateVacioExigeRazonSocialYCuit(): void
    {
        $details = self::detalles([]);

        self::assertArrayHasKey('razon_social', $details);
        self::assertArrayHasKey('cuit', $details);
        self::assertCount(2, $details);
    }

    public function testLanzaValidationExceptionCon422(): void
    {
        try {
            ClienteValidator::validate(['razon_social' => 'X']);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('VALIDATION_ERROR', $e->getErrorCode());
            self::assertArrayHasKey('cuit', $e->getDetails());
        }
    }

    public function testRazonSocialMuyLargaEsRechazada(): void
    {
        $details = self::detalles(['razon_social' => str_repeat('a', 201)] + self::BASE);
        self::assertSame('Requerida, hasta 200 caracteres', $details['razon_social']);
    }

    public function testRazonSocialDe200CaracteresEsValida(): void
    {
        $clean = ClienteValidator::validate(['razon_social' => str_repeat('a', 200)] + self::BASE);
        self::assertSame(200, mb_strlen($clean['razon_social']));
    }

    // ------------------------------------------------------------
    // CUIT
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function cuitsInvalidos(): array
    {
        return [
            'checksum incorrecto' => ['20-12345678-9'],
            'muy corto' => ['20-1234567-6'],
            'letras' => ['2A-12345678-6'],
            'vacio' => [''],
        ];
    }

    #[DataProvider('cuitsInvalidos')]
    public function testRechazaCuitsInvalidos(string $cuit): void
    {
        $details = self::detalles(['cuit' => $cuit] + self::BASE);
        self::assertSame('CUIT inválido (verificá los 11 dígitos y el checksum)', $details['cuit']);
    }

    public function testCuitValidoConOSinGuionesSeNormaliza(): void
    {
        self::assertSame('20-12345678-6', ClienteValidator::validate(['cuit' => '20123456786'] + self::BASE)['cuit']);
        self::assertSame('20-12345678-6', ClienteValidator::validate(['cuit' => ' 20-12345678-6 '] + self::BASE)['cuit']);
    }

    // ------------------------------------------------------------
    // Update parcial
    // ------------------------------------------------------------

    public function testUpdateAceptaPayloadParcial(): void
    {
        $clean = ClienteValidator::validate(['direccion' => 'Av. Corrientes 1234'], true);
        self::assertSame(['direccion' => 'Av. Corrientes 1234'], $clean);
    }

    public function testUpdateVacioDevuelveArrayVacio(): void
    {
        self::assertSame([], ClienteValidator::validate([], true));
    }

    public function testUpdateValidaRazonSocialYCuitSoloSiVienen(): void
    {
        self::assertSame(['razon_social'], array_keys(self::detalles(['razon_social' => ''], true)));
        self::assertSame(['cuit'], array_keys(self::detalles(['cuit' => '20-12345678-9'], true)));
    }

    // ------------------------------------------------------------
    // tipo_default
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function tiposValidos(): array
    {
        $out = [];
        foreach (FacturaVenta::TIPOS as $tipo) {
            $out[$tipo] = [$tipo];
        }
        return $out;
    }

    #[DataProvider('tiposValidos')]
    public function testTipoDefaultAceptaLosTiposDeFactura(string $tipo): void
    {
        $clean = ClienteValidator::validate(['tipo_default' => $tipo] + self::BASE);
        self::assertSame($tipo, $clean['tipo_default']);
    }

    public function testTipoDefaultFueraDeLaListaEsRechazado(): void
    {
        self::assertArrayHasKey('tipo_default', self::detalles(['tipo_default' => 'C'] + self::BASE));
        self::assertArrayHasKey('tipo_default', self::detalles(['tipo_default' => 'a'] + self::BASE));
    }

    public function testTipoDefaultVacioSeNormalizaANull(): void
    {
        self::assertNull(ClienteValidator::validate(['tipo_default' => ''] + self::BASE)['tipo_default']);
    }

    // ------------------------------------------------------------
    // plazo_pago_default
    // ------------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function plazosInvalidos(): array
    {
        return [
            'negativo' => [-1],
            'mayor a 3650' => [3651],
            'texto' => ['treinta'],
        ];
    }

    #[DataProvider('plazosInvalidos')]
    public function testPlazoPagoDefaultInvalidoEsRechazado(mixed $plazo): void
    {
        $details = self::detalles(['plazo_pago_default' => $plazo] + self::BASE);
        self::assertSame('Debe ser un entero entre 0 y 3650 días', $details['plazo_pago_default']);
    }

    public function testPlazoPagoDefaultSeCasteaAEntero(): void
    {
        self::assertSame(30, ClienteValidator::validate(['plazo_pago_default' => '30'] + self::BASE)['plazo_pago_default']);
        self::assertSame(0, ClienteValidator::validate(['plazo_pago_default' => 0] + self::BASE)['plazo_pago_default']);
        self::assertSame(3650, ClienteValidator::validate(['plazo_pago_default' => 3650] + self::BASE)['plazo_pago_default']);
    }

    public function testPlazoPagoDefaultVacioONullPasaANull(): void
    {
        self::assertNull(ClienteValidator::validate(['plazo_pago_default' => ''] + self::BASE)['plazo_pago_default']);
        self::assertNull(ClienteValidator::validate(['plazo_pago_default' => null] + self::BASE)['plazo_pago_default']);
    }

    // ------------------------------------------------------------
    // CBU
    // ------------------------------------------------------------

    public function testCbuDebeTener22Digitos(): void
    {
        self::assertSame('CBU debe tener 22 dígitos', self::detalles(['cbu' => '0170099220000067797'] + self::BASE)['cbu']);
        self::assertArrayHasKey('cbu', self::detalles(['cbu' => str_repeat('1', 23)] + self::BASE));
    }

    public function testCbuValidoSeGuardaSoloConDigitos(): void
    {
        self::assertSame(
            '0170099220000067797370',
            ClienteValidator::validate(['cbu' => '0170099220000067797370'] + self::BASE)['cbu'],
        );
        self::assertSame(
            '0170099220000067797370',
            ClienteValidator::validate(['cbu' => '0170 0992 2000 0067 7973 70'] + self::BASE)['cbu'],
        );
    }

    public function testCbuVacioONullSeNormalizaANull(): void
    {
        self::assertNull(ClienteValidator::validate(['cbu' => ''] + self::BASE)['cbu']);
        self::assertNull(ClienteValidator::validate(['cbu' => null] + self::BASE)['cbu']);
    }

    // ------------------------------------------------------------
    // Emails
    // ------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function camposEmail(): array
    {
        return [
            'mail_envio_factura' => ['mail_envio_factura'],
            'mail_gestion_cobranza' => ['mail_gestion_cobranza'],
        ];
    }

    #[DataProvider('camposEmail')]
    public function testEmailInvalidoEsRechazado(string $campo): void
    {
        self::assertSame('Email inválido', self::detalles([$campo => 'sin-arroba.com'] + self::BASE)[$campo]);
        self::assertArrayHasKey($campo, self::detalles([$campo => 'a@b'] + self::BASE));
    }

    #[DataProvider('camposEmail')]
    public function testEmailValidoPasaYVacioEsNull(string $campo): void
    {
        self::assertSame('pagos@acme.com.ar', ClienteValidator::validate([$campo => 'pagos@acme.com.ar'] + self::BASE)[$campo]);
        self::assertNull(ClienteValidator::validate([$campo => ''] + self::BASE)[$campo]);
    }

    // ------------------------------------------------------------
    // Largos máximos y anti-script
    // ------------------------------------------------------------

    /** @return array<string, array{string, int}> */
    public static function largosMaximos(): array
    {
        return [
            'cuit_pais' => ['cuit_pais', 20],
            'direccion' => ['direccion', 255],
            'banco' => ['banco', 100],
            'alias' => ['alias', 30],
            'contacto_envio_factura' => ['contacto_envio_factura', 150],
            'telefono_contacto_proveedores' => ['telefono_contacto_proveedores', 50],
            'contacto_gestion_cobranza' => ['contacto_gestion_cobranza', 150],
            'telefono_contacto_cobranza' => ['telefono_contacto_cobranza', 50],
        ];
    }

    #[DataProvider('largosMaximos')]
    public function testRechazaStringsQueSuperanElMaximo(string $campo, int $max): void
    {
        $details = self::detalles([$campo => str_repeat('x', $max + 1)] + self::BASE);
        self::assertSame("Máximo {$max} caracteres", $details[$campo]);

        $clean = ClienteValidator::validate([$campo => str_repeat('x', $max)] + self::BASE);
        self::assertSame($max, mb_strlen($clean[$campo]));
    }

    public function testRechazaScriptsEnObservaciones(): void
    {
        $details = self::detalles(['observaciones' => 'hola <script>alert(1)</script>'] + self::BASE);
        self::assertSame('Contenido no permitido', $details['observaciones']);

        self::assertArrayHasKey('observaciones', self::detalles(['observaciones' => '< IFRAME src=x>'] + self::BASE));
    }

    public function testObservacionesConTextoNormalPasan(): void
    {
        $clean = ClienteValidator::validate(['observaciones' => ' Paga a 30 días <b>siempre</b> '] + self::BASE);
        self::assertSame('Paga a 30 días <b>siempre</b>', $clean['observaciones']);
    }

    // ------------------------------------------------------------
    // Normalización / whitelist
    // ------------------------------------------------------------

    public function testActivoSeCasteaABool(): void
    {
        self::assertTrue(ClienteValidator::validate(['activo' => 1] + self::BASE)['activo']);
        self::assertFalse(ClienteValidator::validate(['activo' => 0] + self::BASE)['activo']);
        self::assertFalse(ClienteValidator::validate(['activo' => '0'] + self::BASE)['activo']);
    }

    public function testStringsSeRecortanYVaciosPasanANull(): void
    {
        $clean = ClienteValidator::validate([
            'banco' => '  Santander  ',
            'alias' => '   ',
            'direccion' => null,
        ] + self::BASE);

        self::assertSame('Santander', $clean['banco']);
        self::assertNull($clean['alias']);
        self::assertNull($clean['direccion']);
    }

    public function testClavesFueraDeLaWhitelistNoPasanAlResultado(): void
    {
        $clean = ClienteValidator::validate([
            'id' => 5,
            'created_by' => 1,
            'deleted_at' => null,
            'facturas' => [],
            'rol' => 'admin',
        ] + self::BASE);

        self::assertSame(['razon_social', 'cuit'], array_keys($clean));
    }

    public function testAcumulaTodosLosErroresEnUnaSolaExcepcion(): void
    {
        $details = self::detalles([
            'razon_social' => '',
            'cuit' => '1',
            'tipo_default' => 'Z',
            'plazo_pago_default' => -5,
            'cbu' => '12',
            'mail_envio_factura' => 'x',
        ]);

        self::assertSame(
            ['razon_social', 'cuit', 'tipo_default', 'plazo_pago_default', 'cbu', 'mail_envio_factura'],
            array_keys($details),
        );
    }
}
