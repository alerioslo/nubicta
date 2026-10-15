<?php

namespace App\Services\Reports;

use App\Http\Controllers\DocumentController;
use App\Support\CurrentCompany;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

/** PDF de una tabla (reportes y listados) con el encabezado de la empresa, impreso con Chromium sin interfaz. */
final class TablePdf
{
    public const MAX_ROWS = 5000;

    /**
     * @param  array<int, array{0: string, 1: string}>  $columns  [titulo, formato text|money|qty|int|pct|date]
     * @param  iterable<int, array>  $rows  filas alineadas con $columns
     * @param  array<string, string>  $filters  etiqueta => valor mostrado bajo el titulo
     */
    public static function download(string $file, string $title, array $columns, iterable $rows, ?array $totals = null, array $filters = []): BinaryFileResponse
    {
        $path = self::render($title, $columns, $rows, $totals, $filters);

        return response()->download($path, $file, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }

    public static function render(string $title, array $columns, iterable $rows, ?array $totals = null, array $filters = []): string
    {
        $list = [];
        foreach ($rows as $r) {
            if (count($list) >= self::MAX_ROWS) {
                break;
            }
            $list[] = array_values((array) $r);
        }

        $company = DocumentController::company(CurrentCompany::id());
        if ($company && $company['logo']) {
            $logo = public_path(ltrim($company['logo'], '/'));
            $company['logo'] = is_file($logo) ? 'data:'.(mime_content_type($logo) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($logo)) : null;
        }

        $html = view('reports.table-pdf', [
            'title' => $title, 'columns' => array_values($columns), 'rows' => $list, 'totals' => $totals !== null ? array_values($totals) : null,
            'filters' => $filters, 'company' => $company, 'truncated' => count($list) >= self::MAX_ROWS,
            'landscape' => count($columns) > 6,
        ])->render();

        $base = sys_get_temp_dir().'/tabla_'.bin2hex(random_bytes(6));
        file_put_contents("$base.html", $html);
        $process = new Process([
            config('services.chromium', '/usr/bin/chromium'), '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
            '--no-pdf-header-footer', '--virtual-time-budget=5000', '--run-all-compositor-stages-before-draw', "--print-to-pdf={$base}.pdf", "file://{$base}.html",
        ], null, ['HOME' => sys_get_temp_dir()], null, 180);
        $process->run();
        @unlink("$base.html");
        if (! is_file("$base.pdf") || filesize("$base.pdf") < 500) {
            @unlink("$base.pdf");
            throw new RuntimeException('No se pudo generar el PDF: '.mb_substr(trim($process->getErrorOutput()), 0, 200));
        }

        return "$base.pdf";
    }

    public static function format(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($type) {
            'money', 'qty' => is_numeric($v) ? number_format((float) $v, 2, '.', ',') : (string) $v,
            'cost' => is_numeric($v) ? number_format((float) $v, 6, '.', ',') : (string) $v,
            'pct' => is_numeric($v) ? number_format((float) $v, 2, '.', ',').' %' : (string) $v,
            'int', 'count' => is_numeric($v) ? number_format((float) $v, 0, '.', ',') : (string) $v,
            'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $m) ? "$m[3]/$m[2]/$m[1]" : (string) $v,
            default => (string) $v,
        };
    }
}
