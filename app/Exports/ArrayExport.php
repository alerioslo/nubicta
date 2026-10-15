<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Hoja de Excel a partir de encabezados y filas ya calculadas: encabezados en negrita, formatos numericos reales
 * y, si se indica, un titulo con los filtros encima de la tabla.
 * Formatos por columna: text|money|qty|int|pct|date (si no se indican se deducen de los valores).
 */
class ArrayExport implements FromArray, ShouldAutoSize, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    private const FORMATS = ['money' => '#,##0.00', 'qty' => '#,##0.00', 'int' => '#,##0', 'count' => '#,##0', 'pct' => '0.00"%"', 'date' => 'dd/mm/yyyy'];

    private ?array $data = null;

    public function __construct(
        private array $headings,
        private iterable $rows,
        private string $title = 'Hoja1',
        private array $formats = [],
        private ?string $caption = null,
        private array $filters = [],
        private ?array $totals = null,
    ) {}

    public function array(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $rows = is_array($this->rows) ? array_values($this->rows) : iterator_to_array($this->rows, false);
        $rows = array_map(fn ($r) => array_values((array) $r), $rows);
        if ($this->totals !== null) {
            $rows[] = array_values($this->totals);
        }
        $formats = $this->resolvedFormats($rows);
        foreach ($rows as &$r) {
            foreach ($r as $i => &$v) {
                $f = $formats[$i] ?? 'text';
                if ($v === null || $v === '') {
                    continue;
                }
                if (in_array($f, ['money', 'qty', 'int', 'count', 'pct'], true) && is_numeric($v)) {
                    $v = (float) $v;
                } elseif ($f === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v)) {
                    $v = ExcelDate::stringToExcel(substr((string) $v, 0, 10)) ?: $v;
                } elseif (is_string($v) && str_starts_with($v, '=')) {
                    $v = ' '.$v;
                }
            }
        }

        return $this->data = $rows;
    }

    /** Filas sin la de totales, para exportar el mismo contenido en PDF. */
    public function rowsOnly(): array
    {
        $rows = is_array($this->rows) ? array_values($this->rows) : iterator_to_array($this->rows, false);
        $this->rows = $rows;

        return array_map(fn ($r) => array_values((array) $r), $rows);
    }

    public function formats(): array
    {
        return $this->resolvedFormats($this->rowsOnly());
    }

    public function totalsRow(): ?array
    {
        return $this->totals !== null ? array_values($this->totals) : null;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $this->title), 0, 31);
    }

    public function startCell(): string
    {
        return 'A'.$this->headerRow();
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $cols = max(count($this->headings), 1);
            $last = Coordinate::stringFromColumnIndex($cols);
            $head = $this->headerRow();
            $rows = count($this->array());

            if ($this->caption !== null) {
                $sheet->setCellValue('A1', $this->caption);
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                if ($this->filters) {
                    $sheet->setCellValue('A2', implode('    ', array_map(fn ($k, $v) => "$k: $v", array_keys($this->filters), $this->filters)));
                    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
                }
            }

            $range = "A{$head}:{$last}{$head}";
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9F3FB');
            $sheet->freezePane('A'.($head + 1));
            if ($rows > 0) {
                $sheet->setAutoFilter("A{$head}:{$last}".($head + $rows - ($this->totals !== null ? 1 : 0)));
            }

            foreach ($this->resolvedFormats($this->array()) as $i => $f) {
                if (isset(self::FORMATS[$f]) && $rows > 0) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getStyle("{$col}".($head + 1).":{$col}".($head + $rows))->getNumberFormat()->setFormatCode(self::FORMATS[$f]);
                }
            }
            if ($this->totals !== null && $rows > 0) {
                $sheet->getStyle("A".($head + $rows).":{$last}".($head + $rows))->getFont()->setBold(true);
            }
        }];
    }

    private function headerRow(): int
    {
        return $this->caption !== null ? 4 : 1;
    }

    /** Formatos por columna: los indicados o deducidos (numeros con decimales como dinero, enteros como enteros). */
    private function resolvedFormats(array $rows): array
    {
        if ($this->formats) {
            return array_values($this->formats);
        }
        $out = [];
        foreach (array_keys($this->headings) as $i) {
            $f = 'text';
            foreach (array_slice($rows, 0, 200) as $r) {
                $v = $r[$i] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_float($v) || (is_string($v) && preg_match('/^-?\d+\.\d+$/', $v))) {
                    $f = 'money';
                } elseif (is_int($v) && $f === 'text') {
                    $f = 'int';
                } elseif (! is_int($v)) {
                    $f = 'text';
                    break;
                }
            }
            $out[] = $f;
        }

        return $out;
    }

    public static function download(string $file, array $headings, iterable $rows, string $title = 'Hoja1', array $formats = [], ?string $caption = null, array $filters = [], ?array $totals = null)
    {
        return \Maatwebsite\Excel\Facades\Excel::download(new self($headings, $rows, $title, $formats, $caption, $filters, $totals), $file);
    }
}
