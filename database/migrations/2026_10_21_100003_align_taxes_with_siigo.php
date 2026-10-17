<?php

use App\Services\Accounting\TaxCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja los impuestos: código visible, ReteICA por mil (6 = 6/1000), sin bases mínimas
 * y sin los impuestos y cuentas que no existen en Siigo (solo si no se han usado).
 */
return new class extends Migration
{
    private const NOT_IN_SIIGO = ['IVA Exento', 'IVA Excluido', 'Impoconsumo 4%', 'Retefuente 0.1% combustibles', 'Retefuente 20% loterías y premios',
        'ReteICA 4.14/1000', 'ReteICA 6.9/1000', 'ReteICA 7/1000', 'ReteICA 8/1000', 'ReteICA 9.66/1000', 'ReteICA 11.04/1000', 'Autorretención renta 0.8%'];

    private const ACCOUNTS_NOT_IN_SIIGO = ['13551521', '13551522', '13551523', '13551524', '13551527', '13551825', '13551826',
        '23654008', '23654009', '23657003', '23657004', '23680515', '23680516'];

    public function up(): void
    {
        if (! Schema::hasColumn('taxes', 'code')) {
            Schema::table('taxes', fn (Blueprint $t) => $t->unsignedInteger('code')->nullable()->after('siigo_id'));
        }

        $used = fn (int $id) => collect(['sales_document_item_taxes', 'purchase_document_item_taxes', 'sales_document_retentions', 'purchase_document_retentions', 'product_tax'])
            ->contains(fn ($table) => Schema::hasTable($table) && DB::table($table)->where('tax_id', $id)->exists());

        foreach (DB::table('taxes')->whereNull('siigo_id')->whereIn('name', self::NOT_IN_SIIGO)->get(['id']) as $t) {
            if (! $used($t->id)) {
                DB::table('taxes')->where('id', $t->id)->delete();
            }
        }
        foreach (DB::table('accounts')->whereIn('code', self::ACCOUNTS_NOT_IN_SIIGO)->get(['id']) as $a) {
            $referenced = DB::table('ledger_lines')->where('account_id', $a->id)->exists() || DB::table('journal_lines')->where('account_id', $a->id)->exists()
                || DB::table('taxes')->where(fn ($q) => $q->where('sales_account_id', $a->id)->orWhere('purchases_account_id', $a->id)->orWhere('sales_return_account_id', $a->id)->orWhere('purchases_return_account_id', $a->id))->exists();
            if (! $referenced) {
                DB::table('accounts')->where('id', $a->id)->delete();
            }
        }

        DB::table('taxes')->where('type', 'ReteICA')->where('percentage', '<=', 1.5)->update(['percentage' => DB::raw('percentage * 10')]);

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            app(TaxCatalog::class)->install($companyId);
        }
    }

    public function down(): void
    {
        DB::table('taxes')->where('type', 'ReteICA')->where('percentage', '>', 1.5)->update(['percentage' => DB::raw('percentage / 10')]);
        Schema::table('taxes', fn (Blueprint $t) => $t->dropColumn('code'));
    }
};
