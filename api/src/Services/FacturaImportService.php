<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Helpers\CuitValidator;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Models\User;
use ITHub\Api\Validators\FacturaValidator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Import masivo de facturas históricas desde Excel (.xlsx) o CSV.
 *
 * Flujo en dos pasos:
 *  1. preview(): parsea el archivo, mapea columnas por nombre de cabecera,
 *     valida cada fila (formato, cliente por CUIT, número único en la base y
 *     en el archivo) y devuelve las filas con sus errores más un token. Las
 *     filas válidas quedan guardadas en storage/imports/<token>.json.
 *  2. confirm(): con el token, crea las facturas en una transacción. Todo o
 *     nada por default; con `omitir_con_error` se importan solo las válidas.
 *
 * Reglas (acordadas con el owner):
 *  - El cliente se identifica por CUIT.
 *  - Estado según columnas: `cobrada` = Sí (o fecha_pago cargada) → cobrada
 *    con check; si no, vencida si el vencimiento ya pasó, sino emitida.
 *    fecha_envio = fecha_factura si viene vacía.
 *  - Un número que ya existe es error en esa fila; la existente no se toca.
 *  - Las históricas no se asocian a ninguna cuota de servicio.
 */
final class FacturaImportService
{
    public const MAX_FILAS = 2000;
    private const TOKEN_TTL_SEG = 3600;

    /** Cabeceras aceptadas (ya normalizadas) → campo. */
    private const COLUMNAS = [
        'numero' => 'numero_factura', 'numero_factura' => 'numero_factura', 'nro' => 'numero_factura',
        'nro_factura' => 'numero_factura', 'factura' => 'numero_factura',
        'tipo' => 'tipo', 'tipo_factura' => 'tipo',
        'cuit' => 'cuit',
        'fecha_factura' => 'fecha_factura', 'fecha' => 'fecha_factura', 'fecha_emision' => 'fecha_factura',
        'vencimiento' => 'vencimiento', 'fecha_vencimiento' => 'vencimiento', 'vto' => 'vencimiento',
        'moneda' => 'moneda',
        'sin_iva' => 'importe_sin_iva', 'importe_sin_iva' => 'importe_sin_iva', 'neto' => 'importe_sin_iva',
        'con_iva' => 'importe_con_iva', 'importe_con_iva' => 'importe_con_iva', 'total' => 'importe_con_iva',
        'total_ars' => 'importe_total_pesos', 'importe_total_pesos' => 'importe_total_pesos',
        'total_pesos' => 'importe_total_pesos',
        'tdc' => 'tdc', 'tipo_de_cambio' => 'tdc', 'cotizacion' => 'tdc',
        'total_cobrado' => 'total_cobrado', 'cobrado' => 'total_cobrado',
        'fecha_pago' => 'fecha_pago', 'fecha_cobro' => 'fecha_pago',
        'cobrada' => 'cobrada', 'pagada' => 'cobrada', 'check_cobranza' => 'cobrada',
        'fecha_envio' => 'fecha_envio',
        'detalle' => 'detalle_factura', 'detalle_factura' => 'detalle_factura', 'descripcion' => 'detalle_factura',
        'mes_cubierto' => 'mes_cubierto', 'periodo' => 'mes_cubierto',
        'retenciones' => 'retenciones',
        'observaciones' => 'observaciones', 'obs' => 'observaciones',
    ];

    /** Cabeceras que el export genera y el import ignora a propósito. */
    private const COLUMNAS_IGNORADAS = ['cliente', 'estado', 'razon_social'];

    private const REQUERIDAS = ['numero_factura', 'tipo', 'cuit', 'fecha_factura', 'importe_sin_iva', 'importe_con_iva'];

    private const CAMPOS_FECHA = ['fecha_factura', 'vencimiento', 'fecha_pago', 'fecha_envio'];
    private const CAMPOS_NUMERO = ['importe_sin_iva', 'importe_con_iva', 'importe_total_pesos', 'tdc', 'total_cobrado', 'retenciones'];

    /** Cabeceras de la plantilla, en orden. */
    public const PLANTILLA_CABECERAS = [
        'Número', 'Tipo', 'CUIT', 'Fecha factura', 'Vencimiento', 'Moneda',
        'Sin IVA', 'Con IVA', 'Total ARS', 'TDC', 'Total cobrado', 'Fecha pago', 'Cobrada',
        'Fecha envío', 'Detalle', 'Mes cubierto', 'Retenciones', 'Observaciones',
    ];

    public function __construct(
        private readonly FacturaService $facturas,
        private readonly AuditoriaService $audit,
        private readonly string $importsDir,
    ) {
    }

    // ============================================================
    // PASO 1: PREVIEW
    // ============================================================

    /**
     * @return array{token:string, resumen:array<string,int>, columnas_ignoradas:string[], filas:array<int,array<string,mixed>>}
     */
    public function preview(string $contenido, string $nombreArchivo, User $user): array
    {
        [$cabeceras, $filasCrudas] = $this->leerArchivo($contenido, $nombreArchivo);

        if (count($filasCrudas) > self::MAX_FILAS) {
            throw new ValidationException(
                sprintf('El archivo tiene %d filas y el máximo por import es %d', count($filasCrudas), self::MAX_FILAS),
                ['archivo' => 'demasiadas filas']
            );
        }

        [$mapa, $ignoradas] = $this->mapearCabeceras($cabeceras);
        $faltan = array_diff(self::REQUERIDAS, array_values($mapa));
        if ($faltan !== []) {
            throw new ValidationException(
                'Faltan columnas obligatorias: ' . implode(', ', $faltan)
                . '. Descargá la plantilla para ver el formato esperado.',
                ['columnas' => array_values($faltan)]
            );
        }

        $filas = [];
        $numerosEnArchivo = [];
        $cacheClientes = [];

        foreach ($filasCrudas as $idx => $celdas) {
            $n = $idx + 2; // 1 = cabecera
            if ($this->filaVacia($celdas)) {
                continue;
            }

            $fila = self::mapearFila($celdas, $mapa);
            $errores = [];

            // Cliente por CUIT
            $clienteId = null;
            $clienteNombre = null;
            $cuit = $fila['cuit'] ?? '';
            if ($cuit === '' || !CuitValidator::isValid($cuit)) {
                $errores['cuit'] = 'CUIT inválido';
            } else {
                $cuitNorm = CuitValidator::normalize($cuit);
                if (!array_key_exists($cuitNorm, $cacheClientes)) {
                    $cacheClientes[$cuitNorm] = Cliente::where('cuit', $cuitNorm)->first();
                }
                $cliente = $cacheClientes[$cuitNorm];
                if ($cliente === null) {
                    $errores['cuit'] = "No hay ningún cliente con CUIT {$cuitNorm}";
                } elseif (!$cliente->activo) {
                    $errores['cuit'] = "El cliente {$cliente->razon_social} está inactivo";
                } else {
                    $clienteId = (int) $cliente->id;
                    $clienteNombre = $cliente->razon_social;
                    $fila['cuit'] = $cuitNorm;
                }
            }

            // Validación de formato con el mismo validador que el alta manual
            $datos = $this->aDatosFactura($fila, $clienteId);
            try {
                FacturaValidator::validate($datos, isUpdate: false);
            } catch (ValidationException $e) {
                foreach ($e->getDetails() as $campo => $msg) {
                    // cliente_id es consecuencia del CUIT: ya está reportado ahí
                    if ($campo === 'cliente_id' && isset($errores['cuit'])) {
                        continue;
                    }
                    $errores[$campo] = $errores[$campo] ?? (is_array($msg) ? implode(', ', $msg) : (string) $msg);
                }
            }
            // El validador solo valida importes presentes; los obligatorios del
            // import se reportan explícitamente si faltan o no son numéricos
            foreach (['importe_sin_iva', 'importe_con_iva'] as $f) {
                if ($datos[$f] === '' || $datos[$f] === null) {
                    $errores[$f] = 'Requerido';
                } elseif (!is_numeric($datos[$f])) {
                    $errores[$f] = 'Debe ser un número';
                }
            }

            // Número único en la base y dentro del archivo
            $numero = $datos['numero_factura'] ?? '';
            if ($numero !== '') {
                if (isset($numerosEnArchivo[$numero])) {
                    $errores['numero_factura'] = "Repetido en el archivo (fila {$numerosEnArchivo[$numero]})";
                } else {
                    $numerosEnArchivo[$numero] = $n;
                    if (FacturaVenta::withTrashed()->where('numero_factura', $numero)->exists()) {
                        $errores['numero_factura'] = 'Ya existe una factura con ese número';
                    }
                }
            }

            $filas[] = [
                'n' => $n,
                'datos' => $datos,
                'cobrada' => $this->esCobrada($fila),
                'cliente' => $clienteNombre,
                'estado_previsto' => $this->estadoPrevisto($fila),
                'errores' => $errores,
            ];
        }

        if ($filas === []) {
            throw new ValidationException('El archivo no tiene filas con datos', ['archivo' => 'vacío']);
        }

        $conError = count(array_filter($filas, static fn (array $f): bool => $f['errores'] !== []));
        $token = bin2hex(random_bytes(16));
        $this->guardarPreview($token, $user, $filas, $nombreArchivo);

        return [
            'token' => $token,
            'resumen' => [
                'total' => count($filas),
                'validas' => count($filas) - $conError,
                'con_error' => $conError,
            ],
            'columnas_ignoradas' => $ignoradas,
            'filas' => $filas,
        ];
    }

    // ============================================================
    // PASO 2: CONFIRM
    // ============================================================

    /**
     * @return array{importadas:int, omitidas:int, ids:int[]}
     */
    public function confirm(string $token, bool $omitirConError, User $user, ServerRequestInterface $request): array
    {
        $preview = $this->cargarPreview($token, $user);
        $filas = $preview['filas'];

        $conError = array_filter($filas, static fn (array $f): bool => $f['errores'] !== []);
        if ($conError !== [] && !$omitirConError) {
            throw new ValidationException(
                sprintf('Hay %d fila(s) con error. Corregí el archivo o elegí importar solo las válidas.', count($conError)),
                ['filas_con_error' => array_values(array_map(static fn (array $f): int => $f['n'], $conError))]
            );
        }

        $validas = array_values(array_filter($filas, static fn (array $f): bool => $f['errores'] === []));
        if ($validas === []) {
            throw new ValidationException('No hay filas válidas para importar', ['filas' => 'ninguna válida']);
        }

        $ids = Capsule::connection()->transaction(function () use ($validas, $user, $request): array {
            $ids = [];
            foreach ($validas as $fila) {
                try {
                    $factura = $this->facturas->createHistorica($fila['datos'], (bool) $fila['cobrada'], $user, $request);
                } catch (ValidationException $e) {
                    // Algo cambió entre la preview y el confirm (ej: alguien cargó
                    // ese número a mano). Se aborta todo con la fila señalada.
                    throw new ValidationException(
                        sprintf('Fila %d: %s. No se importó nada.', $fila['n'], $e->getMessage()),
                        ['fila' => $fila['n']] + $e->getDetails()
                    );
                }
                $ids[] = (int) $factura->id;
            }
            return $ids;
        });

        $this->borrarPreview($token);

        $this->audit->log($user->id, 'factura', null, Auditoria::ACCION_IMPORT, [
            'tipo' => 'facturas_historicas',
            'archivo' => $preview['archivo'],
            'importadas' => count($ids),
            'omitidas' => count($filas) - count($ids),
        ], $request);

        return [
            'importadas' => count($ids),
            'omitidas' => count($filas) - count($ids),
            'ids' => $ids,
        ];
    }

    // ============================================================
    // PLANTILLA
    // ============================================================

    /**
     * @return array{filename:string, mime:string, content:string}
     */
    public function plantilla(): array
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Facturas');
        $sheet->fromArray(self::PLANTILLA_CABECERAS, null, 'A1');
        $ultima = chr(ord('A') + count(self::PLANTILLA_CABECERAS) - 1);
        $sheet->getStyle("A1:{$ultima}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$ultima}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('663399');
        $sheet->getStyle("A1:{$ultima}1")->getFont()->getColor()->setRGB('FFFFFF');

        $sheet->fromArray([
            '0001-00000123', 'A', '30-70826853-0', '2025-03-05', '2025-04-04', 'ARS',
            100000, 121000, 121000, '', 121000, '2025-03-28', 'Sí',
            '2025-03-05', 'Mantenimiento Marzo 2025', 'Marzo 2025', 0, '',
        ], null, 'A2');
        $sheet->fromArray([
            'E-0003-00000045', 'E', '30-70826853-0', '2025-06-10', '', 'USD',
            500, 500, '', 1180.50, '', '', 'No',
            '', 'Proyecto web - hito 2', '', '', 'Pendiente de cobro',
        ], null, 'A3');
        foreach (range('A', $ultima) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $ayuda = $book->createSheet();
        $ayuda->setTitle('Instrucciones');
        $lineas = [
            ['Columna', 'Obligatoria', 'Formato / valores'],
            ['Número', 'Sí', 'Texto único. Ej: 0001-00000123'],
            ['Tipo', 'Sí', 'A, B, E, CREDITO MIPYME A, CREDITO MIPYME B, NC A, NC B, NC E, ND A, ND B, ND E'],
            ['CUIT', 'Sí', 'Con o sin guiones. Tiene que existir un cliente activo con ese CUIT'],
            ['Fecha factura', 'Sí', 'AAAA-MM-DD o DD/MM/AAAA (o celda de fecha de Excel)'],
            ['Vencimiento', 'No', 'Misma regla. Si ya pasó y no está cobrada, la factura queda "vencida"'],
            ['Moneda', 'No', 'ARS (default) o USD'],
            ['Sin IVA', 'Sí', 'Número. Acepta 1234.56 o 1.234,56'],
            ['Con IVA', 'Sí', 'Número'],
            ['Total ARS', 'No', 'Si está vacío se calcula: Con IVA (ARS) o Con IVA x TDC (USD)'],
            ['TDC', 'Solo USD', 'Tipo de cambio del día de la factura'],
            ['Total cobrado', 'No', 'Si está cobrada y vacío, se asume el total'],
            ['Fecha pago', 'No', 'Si tiene valor, la factura se considera cobrada'],
            ['Cobrada', 'No', 'Sí / No (también 1/0, true/false, x). Vacío = No'],
            ['Fecha envío', 'No', 'Si está vacía se usa la fecha de factura'],
            ['Detalle', 'No', 'Texto libre (detalle de la factura)'],
            ['Mes cubierto', 'No', 'Ej: Marzo 2025'],
            ['Retenciones', 'No', 'Número'],
            ['Observaciones', 'No', 'Texto libre'],
            [],
            ['Las columnas "Cliente" y "Estado" del export se ignoran: podés reimportar un export sin tocarlo.'],
            ['Las facturas importadas no se asocian a ninguna cuota de servicio (son anteriores al sistema).'],
            ['Un número que ya existe en el sistema da error en esa fila y la factura existente no se modifica.'],
            [sprintf('Máximo %d filas por archivo.', self::MAX_FILAS)],
        ];
        $ayuda->fromArray($lineas, null, 'A1');
        $ayuda->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A', 'B', 'C'] as $col) {
            $ayuda->getColumnDimension($col)->setAutoSize(true);
        }
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = (string) ob_get_clean();

        return [
            'filename' => 'ithub-plantilla-import-facturas.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content,
        ];
    }

    // ============================================================
    // PARSEO (públicos y estáticos para testearlos sin archivo)
    // ============================================================

    /** "Fecha factura" → "fecha_factura"; "Sin IVA" → "sin_iva"; "Nº" → "n". */
    public static function normalizarCabecera(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'º' => '', '°' => '']);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? '';
        return trim($h, '_');
    }

    /**
     * Acepta Y-m-d, d/m/Y, d-m-Y, d.m.Y, un DateTimeInterface o un serial de
     * Excel. Devuelve Y-m-d, o el string original si no lo entiende (así el
     * validador lo reporta como fecha inválida).
     */
    public static function parsearFecha(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{4,6}(\.\d+)?$/', $v))) {
            $serial = (float) $v;
            if ($serial >= 20000 && $serial <= 80000) { // 1954..2119
                return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
            }
        }
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $s, $m)) {
            $anio = (int) $m[3];
            if ($anio < 100) {
                $anio += 2000;
            }
            return sprintf('%04d-%02d-%02d', $anio, (int) $m[2], (int) $m[1]);
        }
        return $s;
    }

    /** "1.234,56" → 1234.56; "1234.56" → 1234.56; "$ 1.234" → 1234; "" → null. */
    public static function parsearNumero(mixed $v): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? '';
        if ($s === '' || $s === '-') {
            return trim((string) $v); // que el validador lo rechace
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            // El último separador es el decimal
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace('.', '', $s)
                : str_replace(',', '', $s);
        }
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : trim((string) $v);
    }

    public static function parsearBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = mb_strtolower(trim((string) $v));
        return in_array($s, ['si', 'sí', 's', 'yes', 'y', '1', 'true', 'x', 'cobrada', 'pagada'], true);
    }

    /** "credito mipyme a" → "CREDITO_MIPYME_A". */
    public static function parsearTipo(mixed $v): string
    {
        $s = mb_strtoupper(trim((string) $v));
        $s = strtr($s, ['É' => 'E', 'Á' => 'A']);
        return preg_replace('/[\s\-]+/', '_', $s) ?? $s;
    }

    /**
     * Celdas de una fila (por índice de columna) → campos del import.
     *
     * @param array<int,mixed> $celdas
     * @param array<int,string> $mapa índice de columna → campo
     * @return array<string,mixed>
     */
    public static function mapearFila(array $celdas, array $mapa): array
    {
        $fila = [];
        foreach ($mapa as $idx => $campo) {
            $v = $celdas[$idx] ?? null;
            if (is_string($v)) {
                $v = trim($v);
            }
            if (in_array($campo, self::CAMPOS_FECHA, true)) {
                $fila[$campo] = self::parsearFecha($v);
            } elseif (in_array($campo, self::CAMPOS_NUMERO, true)) {
                $fila[$campo] = self::parsearNumero($v);
            } elseif ($campo === 'cobrada') {
                $fila[$campo] = self::parsearBool($v);
            } elseif ($campo === 'tipo') {
                $fila[$campo] = self::parsearTipo($v);
            } elseif ($campo === 'moneda') {
                $s = mb_strtoupper(trim((string) $v));
                $fila[$campo] = $s === '' ? 'ARS' : $s;
            } else {
                $fila[$campo] = $v === null ? null : (string) $v;
            }
        }
        return $fila;
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * @return array{0:array<int,string>, 1:array<int,array<int,mixed>>} [cabeceras, filas]
     */
    private function leerArchivo(string $contenido, string $nombre): array
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            return $this->leerCsv($contenido);
        }

        // xlsx / xls: PhpSpreadsheet necesita un archivo en disco
        $tmp = tempnam($this->importsDir, 'imp_');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal del import');
        }
        try {
            file_put_contents($tmp, $contenido);
            $reader = IOFactory::createReaderForFile($tmp);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($tmp)->getSheet(0);
            // Valores crudos: las fechas vienen como serial numérico y las
            // resuelve parsearFecha; los números como float
            $matriz = $sheet->toArray(null, false, false, false);
        } catch (\Throwable $e) {
            throw new ValidationException('No se pudo leer el archivo: ' . $e->getMessage(), ['archivo' => 'ilegible']);
        } finally {
            @unlink($tmp);
        }

        $cabeceras = array_map(static fn (mixed $c): string => trim((string) $c), (array) array_shift($matriz));
        return [$cabeceras, array_values($matriz)];
    }

    /** @return array{0:array<int,string>, 1:array<int,array<int,mixed>>} */
    private function leerCsv(string $contenido): array
    {
        // BOM y saltos de línea Windows
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
        $contenido = str_replace("\r\n", "\n", $contenido);
        $lineas = preg_split('/\n/', trim($contenido)) ?: [];
        if ($lineas === [] || trim($lineas[0]) === '') {
            throw new ValidationException('El archivo está vacío', ['archivo' => 'vacío']);
        }

        // Delimitador: el que más aparece en la cabecera
        $delim = ',';
        $max = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $c = substr_count($lineas[0], $d);
            if ($c > $max) {
                $max = $c;
                $delim = $d;
            }
        }

        $filas = [];
        foreach ($lineas as $linea) {
            $filas[] = str_getcsv($linea, $delim, '"', '\\');
        }
        $cabeceras = array_map(static fn (mixed $c): string => trim((string) $c), array_shift($filas));
        return [$cabeceras, $filas];
    }

    /**
     * @param array<int,string> $cabeceras
     * @return array{0:array<int,string>, 1:string[]} [índice → campo, cabeceras ignoradas]
     */
    private function mapearCabeceras(array $cabeceras): array
    {
        $mapa = [];
        $ignoradas = [];
        foreach ($cabeceras as $idx => $h) {
            if ($h === '') {
                continue;
            }
            $norm = self::normalizarCabecera($h);
            if (isset(self::COLUMNAS[$norm]) && !in_array(self::COLUMNAS[$norm], $mapa, true)) {
                $mapa[$idx] = self::COLUMNAS[$norm];
            } elseif (!in_array($norm, self::COLUMNAS_IGNORADAS, true)) {
                $ignoradas[] = $h;
            }
        }
        return [$mapa, $ignoradas];
    }

    /** @param array<int,mixed> $celdas */
    private function filaVacia(array $celdas): bool
    {
        foreach ($celdas as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Fila mapeada → payload para FacturaValidator / FacturaService.
     *
     * @param array<string,mixed> $fila
     * @return array<string,mixed>
     */
    private function aDatosFactura(array $fila, ?int $clienteId): array
    {
        $datos = [
            'numero_factura' => (string) ($fila['numero_factura'] ?? ''),
            'cliente_id' => $clienteId ?? 0,
            'tipo' => $fila['tipo'] ?? '',
            'cuit' => $fila['cuit'] ?? '',
            'moneda' => $fila['moneda'] ?? 'ARS',
            'fecha_factura' => $fila['fecha_factura'] ?? '',
            'fecha_envio' => $fila['fecha_envio'] ?? ($fila['fecha_factura'] ?? null),
            'vencimiento' => $fila['vencimiento'] ?? null,
            'fecha_pago' => $fila['fecha_pago'] ?? null,
            'importe_sin_iva' => $fila['importe_sin_iva'] ?? null,
            'importe_con_iva' => $fila['importe_con_iva'] ?? null,
            'importe_total_pesos' => $fila['importe_total_pesos'] ?? null,
            'tdc' => $fila['tdc'] ?? null,
            'total_cobrado' => $fila['total_cobrado'] ?? null,
            'retenciones' => $fila['retenciones'] ?? null,
            'detalle_factura' => $fila['detalle_factura'] ?? null,
            'mes_cubierto' => $fila['mes_cubierto'] ?? null,
            'observaciones' => $fila['observaciones'] ?? null,
            'estado' => $this->estadoPrevisto($fila),
        ];

        return $datos;
    }

    /** @param array<string,mixed> $fila */
    private function esCobrada(array $fila): bool
    {
        return !empty($fila['cobrada']) || !empty($fila['fecha_pago']);
    }

    /** @param array<string,mixed> $fila */
    private function estadoPrevisto(array $fila): string
    {
        if ($this->esCobrada($fila)) {
            return FacturaVenta::ESTADO_COBRADA;
        }
        $vto = $fila['vencimiento'] ?? null;
        if (is_string($vto) && $vto !== '' && strtotime($vto) !== false && strtotime($vto) < strtotime(date('Y-m-d'))) {
            return FacturaVenta::ESTADO_VENCIDA;
        }
        return FacturaVenta::ESTADO_EMITIDA;
    }

    // ---------- persistencia de la preview ----------

    private function rutaPreview(string $token): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            throw new ValidationException('Token de import inválido', ['token' => 'inválido']);
        }
        return rtrim($this->importsDir, '/') . "/preview_{$token}.json";
    }

    /** @param array<int,array<string,mixed>> $filas */
    private function guardarPreview(string $token, User $user, array $filas, string $archivo): void
    {
        if (!is_dir($this->importsDir)) {
            @mkdir($this->importsDir, 0750, true);
        }
        $this->purgarPreviewsViejas();
        file_put_contents($this->rutaPreview($token), json_encode([
            'user_id' => $user->id,
            'creado' => time(),
            'archivo' => $archivo,
            'filas' => $filas,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return array{user_id:int, creado:int, archivo:string, filas:array<int,array<string,mixed>>} */
    private function cargarPreview(string $token, User $user): array
    {
        $ruta = $this->rutaPreview($token);
        if (!is_file($ruta)) {
            throw new ValidationException(
                'La previsualización venció o no existe. Volvé a subir el archivo.',
                ['token' => 'no encontrado']
            );
        }
        $preview = json_decode((string) file_get_contents($ruta), true);
        if (!is_array($preview) || (int) ($preview['user_id'] ?? 0) !== (int) $user->id) {
            throw new ValidationException('La previsualización no pertenece a este usuario', ['token' => 'ajeno']);
        }
        if (time() - (int) $preview['creado'] > self::TOKEN_TTL_SEG) {
            @unlink($ruta);
            throw new ValidationException('La previsualización venció. Volvé a subir el archivo.', ['token' => 'vencido']);
        }
        return $preview;
    }

    private function borrarPreview(string $token): void
    {
        @unlink($this->rutaPreview($token));
    }

    private function purgarPreviewsViejas(): void
    {
        foreach (glob(rtrim($this->importsDir, '/') . '/preview_*.json') ?: [] as $f) {
            if (time() - (int) filemtime($f) > self::TOKEN_TTL_SEG) {
                @unlink($f);
            }
        }
    }
}
