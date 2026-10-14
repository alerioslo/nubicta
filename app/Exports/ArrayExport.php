<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Hoja de Excel a partir de encabezados y filas ya calculadas. */
class ArrayExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private array $headings, private iterable $rows, private string $title = 'Hoja1') {}

    public function array(): array
    {
        return is_array($this->rows) ? array_values($this->rows) : iterator_to_array($this->rows, false);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public static function download(string $file, array $headings, iterable $rows, string $title = 'Hoja1')
    {
        return \Maatwebsite\Excel\Facades\Excel::download(new self($headings, $rows, $title), $file);
    }
}
