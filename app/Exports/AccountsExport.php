<?php

namespace App\Exports;

use App\Models\Account;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Excel de "Cuentas contables": codigo, nombre, naturaleza y configuracion de cada cuenta. */
class AccountsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function query(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return Account::query()->orderBy('code');
    }

    public function headings(): array
    {
        return ['Código', 'Nombre', 'Categoría', 'Clase', 'Relación con', 'Maneja vencimientos', 'Diferencia fiscal', 'Activo', 'Nivel agrupación'];
    }

    /** @param Account $a */
    public function map($a): array
    {
        $class = ['1' => 'Activo', '2' => 'Pasivo', '3' => 'Patrimonio', '4' => 'Ingresos', '5' => 'Gasto', '6' => 'Costos de venta', '7' => 'Costos de producción', '8' => 'Cuentas de orden deudoras', '9' => 'Cuentas de orden acreedoras'][$a->code[0]] ?? '';

        return $a->transactional ? [
            $a->code, $a->name, $a->category, $class, $a->relation ?? 'Sin asignar',
            match ($a->due_type) { 'cartera' => 'Con vencimiento en cartera', 'proveedores' => 'Con vencimiento en proveedores', default => 'No maneja vencimiento' },
            $a->fiscal_difference ? 'Sí' : 'No', $a->active ? 'Sí' : 'No', 'Transaccional',
        ] : [$a->code, $a->name];
    }
}
