<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use ITHub\Api\Models\FacturaVenta;
use ITHub\Api\Repositories\FacturaRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Container\ContainerInterface;

/**
 * Exporta listados de facturas a Excel, CSV o PDF.
 *
 * Devuelve {filename, mime, content} para que el controller lo escriba
 * en la response con los headers correctos.
 */
final class ExportService
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly FacturaRepository $facturaRepo
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{filename:string, mime:string, content:string}
     */
    public function exportFacturas(array $filters, string $formato): array
    {
        // Sacamos límite de paginación: queremos TODO lo que matchea los filtros.
        $paginator = $this->facturaRepo->paginate($filters, 1, 10_000);
        $facturas = $paginator->items();

        $timestamp = date('Y-m-d_His');

        return match (strtolower($formato)) {
            'xlsx' => $this->facturasXlsx($facturas, $timestamp),
            'csv' => $this->facturasCsv($facturas, $timestamp),
            'pdf' => $this->facturasPdf($facturas, $timestamp),
            default => throw new \InvalidArgumentException('Formato inválido: usar xlsx, csv o pdf'),
        };
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * @param FacturaVenta[] $facturas
     * @return array{filename:string, mime:string, content:string}
     */
    private function facturasXlsx(array $facturas, string $timestamp): array
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Facturas');

        $headers = [
            'Número', 'Tipo', 'Cliente', 'CUIT',
            'Fecha factura', 'Vencimiento', 'Moneda',
            'Sin IVA', 'Con IVA', 'Total ARS', 'TDC',
            'Total cobrado', 'Fecha pago', 'Estado', 'Cobrada',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:O1')->getFont()->setBold(true);
        $sheet->getStyle('A1:O1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('663399');
        $sheet->getStyle('A1:O1')->getFont()->getColor()->setRGB('FFFFFF');

        $row = 2;
        foreach ($facturas as $f) {
            $sheet->setCellValue("A{$row}", $f->numero_factura);
            $sheet->setCellValue("B{$row}", str_replace('_', ' ', $f->tipo));
            $sheet->setCellValue("C{$row}", $f->cliente?->razon_social ?? '');
            $sheet->setCellValue("D{$row}", $f->cuit);
            $sheet->setCellValue("E{$row}", $f->fecha_factura?->format('Y-m-d') ?? '');
            $sheet->setCellValue("F{$row}", $f->vencimiento?->format('Y-m-d') ?? '');
            $sheet->setCellValue("G{$row}", $f->moneda);
            $sheet->setCellValueExplicit("H{$row}", (float) $f->importe_sin_iva, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("I{$row}", (float) $f->importe_con_iva, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("J{$row}", (float) $f->importe_total_pesos, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValue("K{$row}", $f->tdc !== null ? (float) $f->tdc : '');
            $sheet->setCellValueExplicit("L{$row}", (float) $f->total_cobrado, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValue("M{$row}", $f->fecha_pago?->format('Y-m-d') ?? '');
            $sheet->setCellValue("N{$row}", $f->estado);
            $sheet->setCellValue("O{$row}", $f->check_cobranza ? 'Sí' : 'No');
            $row++;
        }

        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('H:L')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A:O')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $writer = new Xlsx($sheet->getParent());
        ob_start();
        $writer->save('php://output');
        $content = (string) ob_get_clean();

        return [
            'filename' => "facturas_{$timestamp}.xlsx",
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content,
        ];
    }

    /**
     * @param FacturaVenta[] $facturas
     * @return array{filename:string, mime:string, content:string}
     */
    private function facturasCsv(array $facturas, string $timestamp): array
    {
        $headers = [
            'numero', 'tipo', 'cliente', 'cuit',
            'fecha_factura', 'vencimiento', 'moneda',
            'sin_iva', 'con_iva', 'total_ars', 'tdc',
            'total_cobrado', 'fecha_pago', 'estado', 'cobrada',
        ];

        $out = fopen('php://temp', 'w+');
        // BOM UTF-8 para que Excel lo abra correctamente
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);

        foreach ($facturas as $f) {
            // Anti CSV injection: prefijo ' a celdas que empiezan con = + - @
            $row = [
                self::csvSafe($f->numero_factura),
                $f->tipo,
                self::csvSafe($f->cliente?->razon_social ?? ''),
                $f->cuit,
                $f->fecha_factura?->format('Y-m-d') ?? '',
                $f->vencimiento?->format('Y-m-d') ?? '',
                $f->moneda,
                (string) $f->importe_sin_iva,
                (string) $f->importe_con_iva,
                (string) $f->importe_total_pesos,
                $f->tdc !== null ? (string) $f->tdc : '',
                (string) $f->total_cobrado,
                $f->fecha_pago?->format('Y-m-d') ?? '',
                $f->estado,
                $f->check_cobranza ? 'si' : 'no',
            ];
            fputcsv($out, $row);
        }

        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return [
            'filename' => "facturas_{$timestamp}.csv",
            'mime' => 'text/csv; charset=utf-8',
            'content' => $content,
        ];
    }

    /**
     * @param FacturaVenta[] $facturas
     * @return array{filename:string, mime:string, content:string}
     */
    private function facturasPdf(array $facturas, string $timestamp): array
    {
        $options = new Options();
        $options->set('defaultFont', 'sans-serif');
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);

        $rows = '';
        $totalArs = 0.0;
        foreach ($facturas as $f) {
            $totalArs += (float) $f->importe_total_pesos;
            $rows .= sprintf(
                '<tr>'
                . '<td>%s</td><td>%s</td><td>%s</td>'
                . '<td>%s</td><td>%s</td>'
                . '<td style="text-align:right">%s</td>'
                . '<td style="text-align:right">%s</td>'
                . '<td>%s</td>'
                . '</tr>',
                htmlspecialchars($f->numero_factura),
                htmlspecialchars(str_replace('_', ' ', $f->tipo)),
                htmlspecialchars($f->cliente?->razon_social ?? '—'),
                $f->fecha_factura?->format('d/m/Y') ?? '—',
                $f->vencimiento?->format('d/m/Y') ?? '—',
                number_format((float) $f->importe_total_pesos, 2, ',', '.'),
                number_format((float) $f->total_cobrado, 2, ',', '.'),
                htmlspecialchars($f->estado)
            );
        }

        $totalFmt = number_format($totalArs, 2, ',', '.');
        $fecha = date('d/m/Y H:i');
        $count = count($facturas);

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>
body { font-family: sans-serif; font-size: 9px; color: #161922; }
h1 { color: #663399; font-size: 14px; margin: 0 0 4px 0; }
.meta { color: #666; font-size: 9px; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; }
th { background: #663399; color: white; padding: 4px 6px; text-align: left; font-weight: bold; }
td { padding: 4px 6px; border-bottom: 1px solid #eee; }
tfoot td { font-weight: bold; border-top: 2px solid #663399; }
</style></head>
<body>
  <h1>Listado de facturas</h1>
  <div class="meta">{$count} resultado(s) · Generado {$fecha}</div>
  <table>
    <thead><tr>
      <th>Número</th><th>Tipo</th><th>Cliente</th>
      <th>F. factura</th><th>Vencimiento</th>
      <th style="text-align:right">Total ARS</th>
      <th style="text-align:right">Cobrado</th>
      <th>Estado</th>
    </tr></thead>
    <tbody>{$rows}</tbody>
    <tfoot><tr>
      <td colspan="5" style="text-align:right">TOTAL</td>
      <td style="text-align:right">\$ {$totalFmt}</td>
      <td colspan="2"></td>
    </tr></tfoot>
  </table>
</body></html>
HTML;

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $content = $dompdf->output();

        return [
            'filename' => "facturas_{$timestamp}.pdf",
            'mime' => 'application/pdf',
            'content' => $content,
        ];
    }

    private static function csvSafe(string $s): string
    {
        if ($s === '') return '';
        $first = $s[0];
        if (in_array($first, ['=', '+', '-', '@'], true)) {
            return "'" . $s;
        }
        return $s;
    }
}
