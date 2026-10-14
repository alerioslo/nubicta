<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Mismas columnas de la tabla "Productos vendidos" del modulo de deposito. */
class MovementsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private Builder $query) {}

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Código', 'N° factura', 'Fecha', 'Tipo', 'Producto', 'Cliente', 'Identificación', 'Precio', 'Cantidad', 'Cobrado', 'Vendedor', 'Categoría', 'IVA'];
    }

    public function map($r): array
    {
        return [
            $r->code, $r->document, Carbon::parse($r->date)->format('d/m/Y'), $r->type === 'NC' ? 'Nota crédito' : 'Factura',
            $r->product, $r->customer, $r->identification, round((float) $r->price, 2), (float) $r->quantity * $r->sign,
            (float) $r->total * $r->sign, $r->seller, $r->line ?? 'Desconocida', $r->tax_percentage === null ? 'Desconocido' : (float) $r->tax_percentage,
        ];
    }
}
