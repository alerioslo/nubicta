<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;

/**
 * Impuestos que trae Siigo Nube por defecto (Configuración > Contabilidad > Impuestos), con su código, tarifa y
 * las cuatro cuentas: ventas, compras, devolución ventas y devolución compras. La ReteICA se guarda como en Siigo,
 * con la tarifa por mil (6 = 6/1000).
 */
class TaxCatalog
{
    /** [código, nombre, tipo, tarifa, por valor, ventas, compras, devolución ventas, devolución compras] */
    public const TAXES = [
        [1, 'IVA 19%', 'IVA', 19, false, '24080501', '24081001', '24080503', '24081003'],
        [2, 'IVA 5%', 'IVA', 5, false, '24080502', '24081002', '24080504', '24081004'],
        [3, 'Retefuente 11%', 'Retefuente', 11, false, '13551509', '23651501', '13551510', '23651502'],
        [4, 'Retefuente 10%', 'Retefuente', 10, false, '13551507', '23652001', '13551508', '23652002'],
        [5, 'Retefuente 6%', 'Retefuente', 6, false, '13551505', '23652501', '13551506', '23652502'],
        [6, 'Retefuente 4%', 'Retefuente', 4, false, '13551503', '23652503', '13551504', '23652504'],
        [7, 'Retefuente 2.5%', 'Retefuente', 2.5, false, '13551501', '23654001', '13551502', '23654002'],
        [8, 'ReteICA 6/1000 compras', 'ReteICA', 6, false, '13551801', '23680501', '13551802', '23680502'],
        [9, 'ReteICA 6/1000 servicios', 'ReteICA', 6, false, '13551803', '23680503', '13551804', '23680504'],
        [15, 'ReteIVA 15%', 'ReteIVA', 15, false, '13551701', '23670101', '13551702', '23670102'],
        [16, 'Impoconsumo 8%', 'Impoconsumo', 8, false, '24950101', '24950103', '24950102', '24950104'],
        [17, 'Impoconsumo por valor', 'Impoconsumo', 0, true, '246401', '246403', '246402', '246404'],
        [18, 'Retefuente 3.5%', 'Retefuente', 3.5, false, '13551513', '23654004', '13551514', '23654005'],
        [19, 'Retefuente 7%', 'Retefuente', 7, false, '13551511', '23653502', '13551512', '23653503'],
        [20, 'Retefuente 2%', 'Retefuente', 2, false, '13551515', '23657001', '13551516', '23657002'],
        [21, 'Retefuente 1%', 'Retefuente', 1, false, '13551517', '23652505', '13551518', '23652506'],
        [22, 'IVA 0%', 'IVA', 0, false, '24080505', '24081005', '24080506', '24081006'],
        [23, 'Retefuente 1.5%', 'Retefuente', 1.5, false, '13551519', '23654006', '13551520', '23654007'],
    ];

    /** Crea los impuestos que falten y alinea código, tarifa y cuentas con Siigo. @return array{created: int, updated: int} */
    public function install(int $companyId): array
    {
        return DB::transaction(function () use ($companyId) {
            $accounts = Account::withoutGlobalScopes()->where('company_id', $companyId)->pluck('id', 'code');
            $existing = Tax::withoutGlobalScopes()->where('company_id', $companyId)->get();
            $created = $updated = 0;

            foreach (self::TAXES as [$code, $name, $type, $pct, $fixed, $sales, $purchases, $salesReturn, $purchasesReturn]) {
                $tax = $existing->first(fn (Tax $t) => mb_strtolower($t->name) === mb_strtolower($name));
                if (! $tax) {
                    $tax = new Tax(['company_id' => $companyId, 'name' => $name, 'type' => $type, 'active' => true]);
                    $created++;
                } else {
                    $updated++;
                }
                $tax->forceFill(['company_id' => $companyId, 'code' => $code, 'type' => $type, 'percentage' => $pct, 'fixed_value' => $fixed, 'base_minimum' => 0]);
                foreach (['sales_account_id' => $sales, 'purchases_account_id' => $purchases, 'sales_return_account_id' => $salesReturn, 'purchases_return_account_id' => $purchasesReturn] as $col => $acc) {
                    if (isset($accounts[$acc])) {
                        $tax->{$col} = $accounts[$acc];
                    }
                }
                $tax->save();
            }

            return compact('created', 'updated');
        });
    }
}
