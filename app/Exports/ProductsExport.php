<?php

namespace App\Exports;

use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    private $lists;

    public function __construct(private Builder $query)
    {
        $this->lists = PriceList::where('active', true)->orderBy('position')->get(['id', 'name']);
    }

    public function query(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return $this->query->with(['accountGroup:id,name', 'line:id,name', 'taxes:id,name', 'prices'])->orderBy('code');
    }

    public function headings(): array
    {
        return ['Tipo', 'Código', 'Nombre', 'Categoría del producto', 'Línea', 'Unidad de medida', 'Código de barras', 'Referencia',
            'Impuestos', ...$this->lists->pluck('name'), 'Stock', 'Costo promedio', 'Estado'];
    }

    /** @param Product $p */
    public function map($p): array
    {
        return [
            Product::TYPES[$p->type] ?? $p->type, $p->code, $p->name, $p->accountGroup?->name, $p->line?->name,
            $p->unit_label, $p->barcode, $p->reference, $p->taxes->pluck('name')->implode(', '),
            ...$this->lists->map(fn ($l) => (float) $p->prices->firstWhere('price_list_id', $l->id)?->value),
            (float) $p->stock, (float) $p->average_cost, $p->active ? 'Activo' : 'Inactivo',
        ];
    }
}
