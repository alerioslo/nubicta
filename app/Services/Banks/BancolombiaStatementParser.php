<?php

namespace App\Services\Banks;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Lee los extractos/movimientos que descarga la Sucursal Virtual Bancolombia (Personas y Empresas):
 *  - Excel/CSV con encabezado: FECHA, DESCRIPCIÓN, SUCURSAL (o CANAL), REFERENCIA, DCTO., VALOR (o DÉBITO/CRÉDITO), SALDO.
 *  - Archivo plano sin encabezado (TXT/CSV): CUENTA, OFICINA, , FECHA AAAAMMDD, , VALOR, CÓDIGO, DESCRIPCIÓN, ...
 */
class BancolombiaStatementParser
{
    private const HEADERS = [
        'date' => ['FECHA', 'FECHA MOVIMIENTO', 'FECHA TRANSACCION', 'FECHA DE TRANSACCION', 'FECHA OPERACION'],
        'description' => ['DESCRIPCION', 'CONCEPTO', 'DETALLE', 'DESCRIPCION MOVIMIENTO', 'TRANSACCION'],
        'branch' => ['SUCURSAL', 'OFICINA', 'CANAL', 'SUCURSAL/CANAL', 'SUCURSAL CANAL', 'OFICINA/CANAL'],
        'reference' => ['REFERENCIA', 'REFERENCIA 1', 'REF', 'REF 1', 'REFERENCIA1'],
        'document' => ['DCTO', 'DCTO.', 'DOCUMENTO', 'NUMERO DOCUMENTO', 'NRO DOCUMENTO', 'NO. DOCUMENTO'],
        'amount' => ['VALOR', 'MONTO', 'IMPORTE', 'VALOR TRANSACCION'],
        'debit' => ['DEBITO', 'DEBITOS', 'RETIROS', 'CARGOS', 'VALOR DEBITO'],
        'credit' => ['CREDITO', 'CREDITOS', 'CONSIGNACIONES', 'ABONOS', 'VALOR CREDITO'],
        'balance' => ['SALDO', 'SALDO DISPONIBLE', 'SALDO TOTAL', 'NUEVO SALDO'],
    ];

    /** @return array{format:string, lines:list<array{date:string, description:?string, branch:?string, reference:?string, document:?string, amount:float, balance:?float}>, opening:?float, closing:?float} */
    public function parse(string $path, string $name, ?int $year = null): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $rows = in_array($ext, ['xlsx', 'xls', 'ods'], true) ? $this->sheetRows($path) : $this->textRows($path);
        $rows = array_values(array_filter($rows, fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0));
        if (! $rows) {
            throw new RuntimeException('El archivo está vacío.');
        }

        [$headerIdx, $map] = $this->findHeader($rows);
        $lines = $map ? $this->withHeader(array_slice($rows, $headerIdx + 1), $map, $year) : $this->plain($rows, $year);
        if (! $lines) {
            throw new RuntimeException('No se encontraron movimientos: revisa que sea el extracto o movimientos descargados de Bancolombia (FECHA, DESCRIPCIÓN, VALOR).');
        }
        if ($lines[0]['date'] > end($lines)['date']) {
            $lines = array_reverse($lines);
        }

        $opening = $closing = null;
        if ($lines[0]['balance'] !== null) {
            $opening = round($lines[0]['balance'] - $lines[0]['amount'], 2);
            $closing = end($lines)['balance'];
        }

        return ['format' => $map ? 'Bancolombia con encabezado' : 'Bancolombia archivo plano', 'lines' => $lines, 'opening' => $opening, 'closing' => $closing];
    }

    private function sheetRows(string $path): array
    {
        $book = IOFactory::load($path);
        $rows = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $rows = array_merge($rows, $sheet->toArray(null, true, false, false));
            if ($rows) {
                break;
            }
        }

        return $rows;
    }

    private function textRows(string $path): array
    {
        $text = file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $text), 0, 20));
        $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn ($d) => substr_count($sample, $d))->first();

        $rows = [];
        $h = fopen('php://memory', 'r+');
        fwrite($h, $text);
        rewind($h);
        while (($r = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($c) => trim((string) $c), $r);
        }
        fclose($h);

        return $rows;
    }

    private static function norm(string $s): string
    {
        $s = strtoupper(trim(\Illuminate\Support\Str::ascii($s)));

        return trim(preg_replace('/\s+/', ' ', str_replace(['.', ':'], ['', ''], $s)));
    }

    /** @return array{0:int, 1:?array<string,int>} */
    private function findHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            $map = [];
            foreach ($row as $j => $cell) {
                $c = self::norm((string) $cell);
                foreach (self::HEADERS as $key => $names) {
                    if (! isset($map[$key]) && in_array($c, array_map(fn ($n) => self::norm($n), $names), true)) {
                        $map[$key] = $j;
                    }
                }
            }
            if (isset($map['date']) && (isset($map['amount']) || isset($map['debit']) || isset($map['credit']))) {
                return [$i, $map];
            }
        }

        return [0, null];
    }

    private function withHeader(array $rows, array $map, ?int $year): array
    {
        $out = [];
        foreach ($rows as $r) {
            $date = $this->date($r[$map['date']] ?? null, $year);
            if (! $date) {
                continue;
            }
            if (isset($map['amount'])) {
                $amount = $this->amount($r[$map['amount']] ?? null);
            } else {
                $amount = abs((float) $this->amount($r[$map['credit']] ?? null)) - abs((float) $this->amount($r[$map['debit']] ?? null));
            }
            if (! $amount) {
                continue;
            }
            $out[] = [
                'date' => $date, 'amount' => round($amount, 2),
                'description' => $this->text($r, $map['description'] ?? null), 'branch' => $this->text($r, $map['branch'] ?? null),
                'reference' => $this->text($r, $map['reference'] ?? null), 'document' => $this->text($r, $map['document'] ?? null),
                'balance' => isset($map['balance']) ? $this->amount($r[$map['balance']] ?? null) : null,
            ];
        }

        return $out;
    }

    /** Archivo plano sin encabezado: la fecha es la primera celda con forma de fecha y el valor la primera cifra con signo o decimales despues de ella. */
    private function plain(array $rows, ?int $year): array
    {
        $out = [];
        foreach ($rows as $r) {
            $dateIdx = null;
            foreach ($r as $j => $c) {
                if ($j > 0 && ($d = $this->date($c, $year, true))) {
                    $dateIdx = $j;
                    $date = $d;
                    break;
                }
            }
            if ($dateIdx === null) {
                continue;
            }
            $amount = null;
            $amountIdx = null;
            foreach (array_slice($r, $dateIdx + 1, null, true) as $j => $c) {
                if (preg_match('/^-?\$?\s?[\d.,]+$/', trim((string) $c)) && preg_match('/[-.,]/', (string) $c)) {
                    $amount = $this->amount($c);
                    $amountIdx = $j;
                    break;
                }
            }
            if (! $amount) {
                continue;
            }
            $texts = array_filter(array_slice($r, $amountIdx + 1), fn ($c) => preg_match('/[A-Za-z]{3,}/', (string) $c));
            usort($texts, fn ($a, $b) => strlen($b) <=> strlen($a));
            $code = collect(array_slice($r, $amountIdx + 1))->first(fn ($c) => preg_match('/^\d{2,6}$/', (string) $c));
            $out[] = [
                'date' => $date, 'amount' => round($amount, 2), 'description' => $texts[0] ?? null,
                'branch' => isset($r[1]) && preg_match('/^\d{1,5}$/', (string) $r[1]) ? (string) $r[1] : null,
                'reference' => null, 'document' => $code ? (string) $code : null, 'balance' => null,
            ];
        }

        return $out;
    }

    private function text(array $r, ?int $idx): ?string
    {
        if ($idx === null) {
            return null;
        }
        $v = trim((string) ($r[$idx] ?? ''));

        return $v === '' ? null : mb_substr($v, 0, 250);
    }

    public function date(mixed $v, ?int $year = null, bool $strict = false): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (! $strict && is_numeric($s) && (float) $s > 30000 && (float) $s < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $s)->format('Y-m-d');
        }
        if (preg_match('/^(20\d{2})(\d{2})(\d{2})$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        if (preg_match('#^(20\d{2})[/-](\d{1,2})[/-](\d{1,2})#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})#', $s, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (! $strict && $year && preg_match('#^(\d{1,2})/(\d{1,2})$#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], $year) ? sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]) : null;
        }
        if (! $strict && preg_match('/^\d{1,2}\s+[A-Za-z]{3}/', $s)) {
            try {
                return Carbon::parse($s)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** "1.234.567,89", "1,234,567.89", "-4000.00", "$ 12.500", "(3.500,00)" */
    public function amount(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $negative = str_starts_with($s, '-') || str_ends_with($s, '-') || (str_starts_with($s, '(') && str_ends_with($s, ')'));
        $s = preg_replace('/[^\d.,]/', '', $s);
        if ($s === '') {
            return null;
        }
        $dot = strrpos($s, '.');
        $comma = strrpos($s, ',');
        if ($dot !== false && $comma !== false) {
            $decimal = $dot > $comma ? '.' : ',';
        } elseif ($comma !== false) {
            $decimal = strlen($s) - $comma - 1 === 3 && substr_count($s, ',') >= 1 && strlen($s) > 4 ? null : ',';
        } elseif ($dot !== false) {
            $decimal = strlen($s) - $dot - 1 === 3 && (substr_count($s, '.') > 1 || strlen($s) > 4) ? null : '.';
        } else {
            $decimal = null;
        }
        $thousands = $decimal === ',' ? '.' : ($decimal === '.' ? ',' : null);
        if ($thousands) {
            $s = str_replace($thousands, '', $s);
        } else {
            $s = str_replace(['.', ','], '', $s);
        }
        if ($decimal === ',') {
            $s = str_replace(',', '.', $s);
        }
        $n = (float) $s;

        return $negative ? -$n : $n;
    }
}
