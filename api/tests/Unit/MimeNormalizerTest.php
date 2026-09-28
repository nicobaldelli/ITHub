<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Unit;

use ITHub\Api\Support\MimeNormalizer;
use PHPUnit\Framework\TestCase;

final class MimeNormalizerTest extends TestCase
{
    /** Cabecera de un zip local file header + el manifiesto OOXML. */
    private function contenidoOoxml(): string
    {
        return "PK\x03\x04" . str_repeat("\0", 26) . '[Content_Types].xml' . str_repeat('x', 50);
    }

    public function testXlsxDetectadoComoZipSeReconoceSiTieneManifiestoOoxml(): void
    {
        $mime = MimeNormalizer::resolve('application/zip', 'application/octet-stream', 'ventas.xlsx', $this->contenidoOoxml());
        self::assertSame(MimeNormalizer::XLSX, $mime);
    }

    public function testDocxDetectadoComoOctetStreamSeReconoceSiTieneManifiestoOoxml(): void
    {
        $mime = MimeNormalizer::resolve('application/octet-stream', '', 'contrato.DOCX', $this->contenidoOoxml());
        self::assertSame(MimeNormalizer::DOCX, $mime);
    }

    public function testUnZipCualquieraConExtensionXlsxNoSeAceptaComoOffice(): void
    {
        $zipComun = "PK\x03\x04" . str_repeat("\0", 26) . 'archivo.txt' . str_repeat('x', 50);
        $mime = MimeNormalizer::resolve('application/zip', MimeNormalizer::XLSX, 'trucho.xlsx', $zipComun);
        self::assertSame('application/zip', $mime, 'sin [Content_Types].xml queda como zip y la whitelist lo rechaza');
    }

    public function testUnArchivoQueNoEmpiezaConFirmaZipNoSeAceptaComoOffice(): void
    {
        $mime = MimeNormalizer::resolve('application/zip', '', 'x.xlsx', 'no-es-zip [Content_Types].xml');
        self::assertSame('application/zip', $mime);
    }

    public function testCsvDetectadoComoTextoPlanoSeAceptaPorExtension(): void
    {
        $mime = MimeNormalizer::resolve('text/plain', '', 'export.csv', "a,b,c\n1,2,3\n");
        self::assertSame('text/csv', $mime);
    }

    public function testCsvDetectadoComoTextoPlanoSeAceptaPorMimeDeclarado(): void
    {
        self::assertSame('text/csv', MimeNormalizer::resolve('text/plain', 'text/csv', 'datos.txt', 'a,b'));
        self::assertSame('text/csv', MimeNormalizer::resolve('text/plain', 'application/csv', 'datos', 'a,b'));
    }

    public function testTextoPlanoSinIndicioDeCsvQuedaComoTextoPlano(): void
    {
        self::assertSame('text/plain', MimeNormalizer::resolve('text/plain', 'text/plain', 'notas.txt', 'hola'));
    }

    public function testElMimeDetectadoManda(): void
    {
        // El cliente declara PDF pero finfo ve una imagen: gana finfo
        self::assertSame('image/png', MimeNormalizer::resolve('image/png', 'application/pdf', 'factura.pdf', "\x89PNG"));
    }

    public function testSiFinfoNoDetectaNadaUsaElDeclarado(): void
    {
        self::assertSame('application/pdf', MimeNormalizer::resolve('', 'application/pdf', 'f.pdf', '%PDF-1.4'));
    }
}
