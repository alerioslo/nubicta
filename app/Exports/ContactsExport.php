<?php

namespace App\Exports;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContactsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private Builder $query) {}

    public function query(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return $this->query->with('municipality:code,name')->orderBy('name');
    }

    public function headings(): array
    {
        return ['Tipo de identificación', 'Identificación', 'DV', 'Sucursal', 'Nombre', 'Nombre comercial', 'Tipo de tercero',
            'Dirección', 'Ciudad', 'Teléfono', 'Celular', 'Correo electrónico', 'Responsabilidades fiscales', 'Estado'];
    }

    /** @param Contact $c */
    public function map($c): array
    {
        return [
            config('erp.identification_types')[$c->identification_type] ?? $c->identification_type,
            $c->identification, $c->dv, $c->branch_office, $c->name, $c->trade_name, $c->rolesLabel(),
            $c->address, $c->municipality?->name, $c->phone, $c->mobile, $c->email,
            implode(', ', $c->fiscal_responsibilities ?? []), $c->active ? 'Activo' : 'Inactivo',
        ];
    }
}
