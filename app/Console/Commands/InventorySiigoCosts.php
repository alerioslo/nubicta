<?php

namespace App\Console\Commands;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Services\Accounting\AccountResolver;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Toma el "Valor unitario aproximado" del Excel de Saldos de inventario de Siigo como costo del saldo inicial
 * y recalcula el kardex, el costo de las lineas y el asiento de costo de las ventas hechas en el ERP.
 */
class InventorySiigoCosts extends Command
{
    protected $signature = 'inventory:siigo-costs {file : Excel "Saldos de inventario" de Siigo con todos los saldos} {--company=1} {--dry}';

    protected $description = 'Ajusta el costo promedio de los productos al de Siigo y recalcula kardex y costo de ventas';

    public function handle(AccountResolver $accounts): int
    {
        CurrentCompany::set((int) $this->option('company'));
        $costs = [];
        foreach (IOFactory::load($this->argument('file'))->getActiveSheet()->toArray(null, true, false, false) as $r) {
            if (isset($r[0], $r[6]) && is_numeric($r[6]) && $r[0] !== 'Código producto') {
                $costs[(string) $r[0]] = round((float) $r[6], 6);
            }
        }
        if (count($costs) < 10) {
            $this->error('El archivo no parece el Excel de Saldos de inventario de Siigo.');

            return self::FAILURE;
        }

        $products = Product::query()->whereIn('code', array_keys($costs))->get();
        $changed = 0;
        $docs = [];
        DB::beginTransaction();
        foreach ($products as $p) {
            $target = $costs[$p->code];
            $moves = InventoryMovement::query()->where('product_id', $p->id)->orderBy('date')->orderBy('id')->get();
            $opening = $moves->firstWhere('concept', 'saldo_inicial');
            if (abs((float) $p->average_cost - $target) < 0.005 && (! $opening || abs((float) $opening->average_cost - $target) < 0.005)) {
                continue;
            }
            $changed++;
            $qty = 0.0;
            $avg = $target;
            foreach ($moves as $m) {
                $q = (float) $m->quantity;
                if ($m->concept === 'saldo_inicial') {
                    $unit = $target;
                    $avg = $target;
                } elseif ($q > 0 && in_array($m->source_type, ['App\\Models\\PurchaseDocument'], true)) {
                    $unit = (float) $m->unit_cost;
                    $avg = $qty + $q > 0 ? ($qty * $avg + $q * $unit) / ($qty + $q) : $unit;
                } else {
                    $unit = $avg;
                }
                $qty += $q;
                $m->forceFill(['unit_cost' => $unit, 'total_cost' => round($q * $unit, 2), 'balance_quantity' => $qty, 'balance_cost' => round($qty * $avg, 2), 'average_cost' => $avg])->save();
                if ($m->source_type === 'App\\Models\\SalesDocument') {
                    $docs[$m->source_id] = true;
                    DB::table('sales_document_items')->where('sales_document_id', $m->source_id)->where('product_id', $p->id)->update(['unit_cost' => $unit]);
                }
            }
            $p->forceFill(['average_cost' => $avg])->saveQuietly();
        }

        $fixed = 0;
        foreach (SalesDocument::query()->whereIn('id', array_keys($docs))->where('status', '!=', 'annulled')->get() as $doc) {
            $byAccount = [];
            $moves = InventoryMovement::query()->with('product.accountGroup')->where('source_type', 'App\\Models\\SalesDocument')->where('source_id', $doc->id)->get();
            foreach ($moves as $m) {
                $cost = abs((float) $m->total_cost);
                $group = $m->product?->accountGroup;
                $byAccount[$accounts->code($accounts->group($group, 'cost'))] = ($byAccount[$accounts->code($accounts->group($group, 'cost'))] ?? 0) + $cost;
                $byAccount[$accounts->code($accounts->group($group, 'inventory'))] = ($byAccount[$accounts->code($accounts->group($group, 'inventory'))] ?? 0) + $cost;
            }
            foreach ($byAccount as $code => $value) {
                $line = DB::table('ledger_lines')->where('document', $doc->name)->where('source', 'erp')->where('account_code', $code)->first();
                if (! $line) {
                    continue;
                }
                $side = (float) $line->debit > 0 ? 'debit' : 'credit';
                if (abs((float) $line->$side - round($value, 2)) >= 0.01) {
                    DB::table('ledger_lines')->where('id', $line->id)->update([$side => round($value, 2)]);
                    $fixed++;
                }
            }
        }

        $this->info("Productos ajustados: $changed. Ventas del ERP con costo recalculado: ".count($docs).". Líneas contables corregidas: $fixed.");
        if ($this->option('dry')) {
            DB::rollBack();
            $this->warn('Simulación: no se guardó nada.');
        } else {
            DB::commit();
        }

        return self::SUCCESS;
    }
}
