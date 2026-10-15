<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\MonthlyCosting;
use Illuminate\Http\RedirectResponse;

/** Costeo mensual: calcular / recalcular un mes y descargar su detalle por producto. */
class CostingController extends Controller
{
    public function run(int $year, int $month, MonthlyCosting $costing): RedirectResponse
    {
        abort_unless($month >= 1 && $month <= 12, 404);
        try {
            $r = $costing->run($year, $month);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $msg = "Se costeó el mes: {$r['products']} productos y {$r['movements']} movimientos recalculados.";
        $msg .= $r['entry'] ? " Ajuste al costo de ventas por $ ".number_format($r['adjustment'], 2, ',', '.')." en el comprobante {$r['entry']}." : ' No hubo diferencias en el costo de ventas.';

        return back()->with('success', $msg);
    }

    public function excel(int $year, int $month, MonthlyCosting $costing)
    {
        abort_unless($month >= 1 && $month <= 12, 404);

        return \App\Support\Download::array(sprintf('costeo_%d_%02d.xlsx', $year, $month), [
            'Código', 'Producto', 'Saldo inicial cantidad', 'Saldo inicial costo', 'Entradas cantidad', 'Entradas costo',
            'Salidas cantidad', 'Salidas costo', 'Saldo final cantidad', 'Saldo final costo', 'Costo promedio',
        ], $costing->detail($year, $month), sprintf('Costeo %02d-%d', $month, $year));
    }
}
