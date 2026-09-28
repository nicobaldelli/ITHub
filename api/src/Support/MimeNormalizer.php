<?php

declare(strict_types=1);

namespace ITHub\Api\Support;

/**
 * Resuelve el MIME a validar contra la whitelist de uploads.
 *
 * Se prioriza lo que detecta finfo (magic bytes) sobre lo que declara el
 * cliente, pero hay dos casos en los que finfo no alcanza y se confirma con
 * una segunda verificación sobre el contenido:
 *  - xlsx/docx: finfo devuelve application/zip. Son válidos solo si el zip
 *    contiene el manifiesto OOXML "[Content_Types].xml".
 *  - csv: finfo devuelve text/plain. Se acepta como text/csv solo si el
 *    cliente lo declaró csv o la extensión es .csv.
 */
final class MimeNormalizer
{
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const OFFICE_POR_EXTENSION = [
        'xlsx' => self::XLSX,
        'docx' => self::DOCX,
    ];

    public static function resolve(string $detected, string $reported, string $nombre, string $contenido): string
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        if (
            in_array($detected, ['application/zip', 'application/octet-stream'], true)
            && isset(self::OFFICE_POR_EXTENSION[$ext])
            && str_starts_with($contenido, "PK\x03\x04")
            && str_contains($contenido, '[Content_Types].xml')
        ) {
            return self::OFFICE_POR_EXTENSION[$ext];
        }

        if (
            $detected === 'text/plain'
            && ($ext === 'csv' || in_array($reported, ['text/csv', 'application/csv'], true))
        ) {
            return 'text/csv';
        }

        return $detected !== '' ? $detected : $reported;
    }
}
