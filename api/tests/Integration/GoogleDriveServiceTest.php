<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use Google\Client as GoogleClient;
use Google\Service\Drive as DriveService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Services\GoogleDriveService;
use ITHub\Api\Tests\Support\ArrayContainer;
use ITHub\Api\Tests\Support\DatabaseTestCase;
use ITHub\Api\Tests\Support\TestSettings;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * GoogleDriveService contra un cliente HTTP mockeado (sin red): verifica la
 * estructura de carpetas, el reuso de carpetas existentes y, sobre todo,
 * que TODAS las llamadas lleven supportsAllDrives (sin eso la API no ve
 * nada dentro de una Unidad compartida).
 */
final class GoogleDriveServiceTest extends DatabaseTestCase
{
    private string $saPath;
    private MockHandler $mock;
    /** @var array<int, array{request: RequestInterface}> */
    private array $historial = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Solo se chequea que exista y sea legible: el JSON nunca se parsea
        // porque inyectamos el DriveService ya armado
        $this->saPath = tempnam(sys_get_temp_dir(), 'sa_');
        file_put_contents($this->saPath, '{"type":"service_account","client_email":"fake@test"}');

        Capsule::table('config_app')->insert([
            'clave' => 'drive_root_folder_id', 'valor' => 'ROOT', 'tipo' => 'string', 'updated_at' => $this->now(),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->saPath);
        parent::tearDown();
    }

    /** @param Response[] $respuestas */
    private function servicio(array $respuestas): GoogleDriveService
    {
        $settings = TestSettings::get();
        $settings['google_drive']['service_account_path'] = $this->saPath;

        $svc = new GoogleDriveService(
            new ArrayContainer(['settings' => $settings, 'basePath' => dirname(__DIR__, 2)]),
            new NullLogger(),
        );

        $this->mock = new MockHandler($respuestas);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->historial));

        $client = new GoogleClient();
        $client->setHttpClient(new HttpClient(['handler' => $stack]));
        // Token ya "obtenido": evita el round trip de OAuth
        $client->setAccessToken(['access_token' => 'fake', 'expires_in' => 3600, 'created' => time()]);

        $svc->useDriveService(new DriveService($client));
        return $svc;
    }

    private static function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<string,string> query string del request N del historial */
    private function query(int $n): array
    {
        parse_str($this->historial[$n]['request']->getUri()->getQuery(), $q);
        return $q;
    }

    public function testUploadCreaLaJerarquiaClienteAnioMesReusandoLoQueExiste(): void
    {
        $svc = $this->servicio([
            self::json(['files' => []]),                                   // buscar carpeta cliente: no existe
            self::json(['id' => 'F-CLIENTE']),                             // crear carpeta cliente
            self::json(['files' => [['id' => 'F-2026', 'name' => '2026']]]), // buscar año: existe
            self::json(['files' => []]),                                   // buscar mes: no existe
            self::json(['id' => 'F-MES']),                                 // crear mes
            self::json([                                                   // subir archivo
                'id' => 'FILE-1', 'name' => '0001-00000123.pdf', 'mimeType' => 'application/pdf',
                'size' => '9', 'webViewLink' => 'https://drive/view', 'webContentLink' => 'https://drive/dl',
            ]),
        ]);

        $res = $svc->uploadFacturaArchivo(
            'Acme / Sucursal "Norte"',
            new \DateTimeImmutable('2026-06-15'),
            'adjunto.pdf',
            'application/pdf',
            9,
            '%PDF-1.4 ',
            forcedFileName: '0001-00000123.pdf',
        );

        self::assertSame([
            'drive_file_id' => 'FILE-1',
            'drive_view_url' => 'https://drive/view',
            'drive_download_url' => 'https://drive/dl',
            'mime_type' => 'application/pdf',
            'tamanio_bytes' => 9,
        ], $res);

        self::assertCount(6, $this->historial);

        // 1. búsqueda de la carpeta del cliente bajo la raíz, nombre saneado y escapado
        $q0 = $this->query(0);
        self::assertStringContainsString("name = 'Acme _ Sucursal _Norte_'", $q0['q']);
        self::assertStringContainsString("'ROOT' in parents", $q0['q']);

        // 2. creación de la carpeta del cliente colgando de la raíz
        $body1 = json_decode((string) $this->historial[1]['request']->getBody(), true);
        self::assertSame('Acme _ Sucursal _Norte_', $body1['name']);
        self::assertSame(['ROOT'], $body1['parents']);
        self::assertSame('application/vnd.google-apps.folder', $body1['mimeType']);

        // 3. el año existía: no se crea, y el mes se busca bajo F-2026
        self::assertStringContainsString("'F-CLIENTE' in parents", $this->query(2)['q']);
        self::assertStringContainsString("name = '06-Junio'", $this->query(3)['q']);
        self::assertStringContainsString("'F-2026' in parents", $this->query(3)['q']);

        // 5. el archivo va a la carpeta del mes con el nombre forzado
        $upload = $this->historial[5]['request'];
        self::assertStringContainsString('/upload/drive/v3/files', (string) $upload->getUri());
        self::assertSame('multipart', $this->query(5)['uploadType']);
        self::assertStringContainsString('"parents":["F-MES"]', (string) $upload->getBody());
        self::assertStringContainsString('"name":"0001-00000123.pdf"', (string) $upload->getBody());
    }

    public function testTodasLasLlamadasHabilitanUnidadesCompartidas(): void
    {
        $svc = $this->servicio([
            self::json(['files' => [['id' => 'F-C', 'name' => 'c']]]),
            self::json(['files' => [['id' => 'F-A', 'name' => '2026']]]),
            self::json(['files' => [['id' => 'F-M', 'name' => '06-Junio']]]),
            self::json(['id' => 'FILE-1', 'mimeType' => 'application/pdf', 'size' => '3']),
            new Response(204),                                              // delete
            self::json(['id' => 'FILE-1', 'webContentLink' => 'https://drive/dl']), // get
        ]);

        $svc->uploadFacturaArchivo('Cliente', new \DateTimeImmutable('2026-06-01'), 'a.pdf', 'application/pdf', 3, 'abc');
        $svc->deleteFile('FILE-1');
        self::assertSame('https://drive/dl', $svc->getDownloadUrl('FILE-1'));

        self::assertCount(6, $this->historial);
        foreach ($this->historial as $i => $h) {
            $q = $this->query($i);
            $uri = (string) $h['request']->getUri();
            self::assertSame('true', $q['supportsAllDrives'] ?? null, "request {$i} ({$uri}) sin supportsAllDrives");
        }
        // Las búsquedas además tienen que incluir ítems de todas las unidades
        foreach ([0, 1, 2] as $i) {
            $q = $this->query($i);
            self::assertSame('true', $q['includeItemsFromAllDrives'] ?? null, "búsqueda {$i} sin includeItemsFromAllDrives");
            self::assertSame('allDrives', $q['corpora'] ?? null, "búsqueda {$i} sin corpora");
        }

        self::assertSame('DELETE', $this->historial[4]['request']->getMethod());
        self::assertStringContainsString('/drive/v3/files/FILE-1', (string) $this->historial[4]['request']->getUri());
    }

    public function testGetDownloadUrlNoExplotaSiDriveFalla(): void
    {
        $svc = $this->servicio([
            new Response(404, ['Content-Type' => 'application/json'], '{"error":{"message":"File not found"}}'),
        ]);

        self::assertNull($svc->getDownloadUrl('NO-EXISTE'));
    }

    public function testRechazaAntesDeLlamarADriveSiElMimeOElTamanioNoCumplen(): void
    {
        $svc = $this->servicio([]);

        try {
            $svc->uploadFacturaArchivo('C', new \DateTimeImmutable(), 'x.exe', 'application/x-msdownload', 10, 'MZ');
            self::fail('mime no permitido');
        } catch (ValidationException $e) {
            self::assertStringContainsString('no permitido', $e->getDetails()['archivo']);
        }

        try {
            $svc->uploadFacturaArchivo('C', new \DateTimeImmutable(), 'x.pdf', 'application/pdf', 26 * 1024 * 1024, 'x');
            self::fail('demasiado grande');
        } catch (ValidationException $e) {
            self::assertSame('tamaño excedido', $e->getDetails()['archivo']);
        }

        try {
            $svc->uploadFacturaArchivo('C', new \DateTimeImmutable(), '../x.pdf', 'application/pdf', 10, 'x');
            self::fail('path traversal en el nombre');
        } catch (ValidationException $e) {
            self::assertSame('nombre', $e->getDetails()['archivo']);
        }

        self::assertCount(0, $this->historial, 'ninguna llamada a Drive');
    }

    public function testNoEstaDisponibleSinRootFolderOSinJson(): void
    {
        $svc = $this->servicio([]);
        self::assertTrue($svc->isAvailable());

        Capsule::table('config_app')->where('clave', 'drive_root_folder_id')->update(['valor' => '']);
        self::assertFalse($svc->isAvailable());
        try {
            $svc->uploadFacturaArchivo('C', new \DateTimeImmutable(), 'x.pdf', 'application/pdf', 10, 'x');
            self::fail('sin root');
        } catch (ValidationException $e) {
            self::assertSame('root_folder_missing', $e->getDetails()['drive']);
        }

        Capsule::table('config_app')->where('clave', 'drive_root_folder_id')->update(['valor' => 'ROOT']);
        unlink($this->saPath);
        self::assertFalse($svc->isAvailable());
    }
}
