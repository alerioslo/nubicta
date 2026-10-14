<?php

namespace App\Services\Inventory;

use App\Models\InventoryCosting;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Services\Accounting\AccountResolver;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Costeo mensual: recalcula en orden cronologico el costo promedio ponderado de cada producto con movimientos en el mes
 * (documentos registrados con fecha anterior cambian el promedio de las salidas posteriores) y contabiliza en el
 * comprobante CC-3 Costeo la diferencia del costo de ventas contra el inventario.
 */
class MonthlyCosting
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly AccountResolver $accounts,
        private readonly JournalRegistrar $journals,
        private readonly DocumentReverser $reverser,
    ) {}

    /** @return array{products:int, movements:int, adjustment:float, entry:?string} */
    public function run(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth();
        if ($from->isFuture()) {
            throw new RuntimeException('No puedes costear un mes que aún no ha comenzado.');
        }
        $prev = $from->copy()->subMonth();
        $prevDone = InventoryCosting::query()->where(['year' => $prev->year, 'month' => $prev->month, 'status' => 'Costeado'])->exists();
        if (! $prevDone && InventoryMovement::query()->whereDate('date', '<', $from)->exists()) {
            throw new RuntimeException("Primero debes costear {$prev->translatedFormat('F \d\e Y')}.");
        }
        $later = InventoryCosting::query()->where('status', 'Costeado')->where(fn ($q) => $q->where('year', '>', $year)->orWhere(fn ($w) => $w->where('year', $year)->where('month', '>', $month)))->exists();
        if ($later && InventoryMovement::query()->whereDate('date', '>', $to)->exists()) {
            throw new RuntimeException('Hay meses posteriores costeados con movimientos. Recalcula desde el mes más antiguo en orden.');
        }

        return DB::transaction(function () use ($year, $month, $from, $to) {
            $label = sprintf('%02d/%d', $month, $year);
            foreach (JournalEntry::query()->where('source_type', 'inventory_costing')->where('status', '!=', 'annulled')->where('observations', 'like', "Costeo mensual $label%")->get() as $old) {
                $this->reverser->annul($old);
            }

            $byGroup = [];
            $count = 0;
            $productIds = InventoryMovement::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->distinct()->pluck('product_id');
            foreach ($productIds as $pid) {
                $count += $this->recost((int) $pid, $from->toDateString(), $to->toDateString(), $byGroup);
            }

            $entry = $this->post($byGroup, $to->toDateString(), $label);
            $costing = InventoryCosting::query()->updateOrCreate(['year' => $year, 'month' => $month], ['executed_at' => now()->toDateString(), 'status' => 'Costeado']);
            if ($entry) {
                $entry->forceFill(['source_id' => $costing->id])->save();
            }

            return ['products' => $productIds->count(), 'movements' => $count, 'adjustment' => round(array_sum(array_map('abs', $byGroup)), 2), 'entry' => $entry?->name];
        });
    }

    /** Recalcula los movimientos del mes de un producto; acumula por categoria la diferencia del costo de ventas. */
    private function recost(int $productId, string $from, string $to, array &$byGroup): int
    {
        $product = Product::query()->find($productId);
        if (! $product) {
            return 0;
        }
        $opening = InventoryMovement::query()->where('product_id', $productId)->whereDate('date', '<', $from)->orderByDesc('date')->orderByDesc('id')->first();
        $qty = (float) ($opening?->balance_quantity ?? 0);
        $avg = (float) ($opening?->average_cost ?? 0);
        $sale = (new SalesDocument)->getMorphClass();
        $rows = InventoryMovement::query()->where('product_id', $productId)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();

        foreach ($rows as $m) {
            $q = (float) $m->quantity;
            if ($q > 0 && $m->source_type !== $sale) {
                $unit = (float) $m->unit_cost;
                $newQty = $qty + $q;
                $avg = $newQty > 0 ? (($qty * $avg) + ($q * $unit)) / $newQty : $unit;
            } else {
                $unit = $avg;
            }
            $qty += $q;
            $total = round($q * $unit, 2);
            if ($m->source_type === $sale) {
                $diff = round((float) $m->total_cost - $total, 2);
                if ($diff != 0) {
                    $byGroup[$product->account_group_id ?? 0] = round(($byGroup[$product->account_group_id ?? 0] ?? 0) + $diff, 2);
                }
            }
            $m->forceFill(['unit_cost' => $unit, 'total_cost' => $total, 'balance_quantity' => $qty, 'balance_cost' => round($qty * $avg, 2), 'average_cost' => $avg])->save();
        }
        $last = InventoryMovement::query()->where('product_id', $productId)->orderByDesc('date')->orderByDesc('id')->first();
        if ($last && $rows->contains('id', $last->id)) {
            $product->forceFill(['average_cost' => $avg])->saveQuietly();
        }

        return $rows->count();
    }

    /** Diferencia positiva = mas costo de ventas (debito costo / credito inventario). */
    private function post(array $byGroup, string $date, string $label): ?JournalEntry
    {
        $lines = [];
        $groups = \App\Models\AccountGroup::query()->whereIn('id', array_keys($byGroup))->get()->keyBy('id');
        foreach ($byGroup as $groupId => $diff) {
            if (abs($diff) < 0.01) {
                continue;
            }
            $g = $groups->get($groupId);
            $cost = $this->accounts->group($g, 'cost');
            $inventory = $this->accounts->group($g, 'inventory');
            $v = abs($diff);
            $lines[] = ['account_id' => $diff > 0 ? $cost : $inventory, 'contact' => null, 'description' => "Costeo $label ".($g?->name ?? ''), 'debit' => $v, 'credit' => 0];
            $lines[] = ['account_id' => $diff > 0 ? $inventory : $cost, 'contact' => null, 'description' => "Costeo $label ".($g?->name ?? ''), 'debit' => 0, 'credit' => $v];
        }
        if (! $lines) {
            return null;
        }

        return $this->journals->store($this->numbering->journalType('3', 'Costeo'), ['date' => $date, 'observations' => "Costeo mensual $label"], $lines, 'inventory_costing');
    }

    /** Detalle por producto del mes (saldo inicial, entradas, salidas y saldo final). */
    public function detail(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $rows = [];
        $products = Product::query()->whereIn('id', InventoryMovement::query()->whereBetween('date', [$from, $to])->distinct()->pluck('product_id'))->orderBy('code')->get(['id', 'code', 'name']);
        foreach ($products as $p) {
            $opening = InventoryMovement::query()->where('product_id', $p->id)->whereDate('date', '<', $from)->orderByDesc('date')->orderByDesc('id')->first();
            $moves = InventoryMovement::query()->where('product_id', $p->id)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
            $in = $moves->where('quantity', '>', 0);
            $out = $moves->where('quantity', '<', 0);
            $last = $moves->last();
            $rows[] = [
                $p->code, $p->name, (float) ($opening?->balance_quantity ?? 0), (float) ($opening?->balance_cost ?? 0),
                (float) $in->sum('quantity'), (float) $in->sum('total_cost'), (float) -$out->sum('quantity'), (float) -$out->sum('total_cost'),
                (float) $last->balance_quantity, (float) $last->balance_cost, round((float) $last->average_cost, 2),
            ];
        }

        return $rows;
    }
}
