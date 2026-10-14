<?php

namespace App\Services\Documents;

use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerEntry;
use App\Services\Inventory\InventoryService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Notas de inventario. Cada nota queda como comprobante (encabezado en journal_entries) con sus movimientos de kardex;
 * solo el ajuste con valores contabiliza, el traslado y el conteo mueven cantidades como en Siigo.
 */
class InventoryRegistrar
{
    public function __construct(
        private readonly Numbering $numbering,
        private readonly AccountResolver $accounts,
        private readonly InventoryService $inventory,
    ) {}

    public function register(string $kind, array $data, array $fallbacks = []): JournalEntry
    {
        $this->numbering->assertOpen($data['date']);
        $items = collect($data['items'] ?? [])->filter(fn ($i) => ! empty($i['product_id']))->values();
        $products = Product::query()->with('accountGroup')->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
        $contact = Contact::query()->find($data['contact_id'] ?? 0);
        $type = match ($kind) {
            'traslado' => $this->numbering->journalType('10', 'NOTAS DE TRASLADO DE INVENTARIOS'),
            'ajuste', 'conteo' => $this->numbering->journalType('1', 'Ajustes contables'),
            'saldo' => $this->numbering->journalType('999', 'Saldos iniciales'),
            'ensamble' => $this->numbering->type($data['document_type'] ?? null, 'NE', $fallbacks),
        };
        $warehouse = (int) Warehouse::query()->where('active', true)->orderBy('id')->value('id');

        return DB::transaction(function () use ($kind, $data, $items, $products, $contact, $type, $warehouse) {
            [$number, $name] = $this->numbering->take($type, 'journal_entries');
            $je = JournalEntry::create([
                'company_id' => CurrentCompany::id(), 'source' => 'erp', 'document_type_id' => $type->id, 'source_type' => 'inventory_'.$kind,
                'number' => $number, 'name' => $name, 'date' => $data['date'], 'observations' => $data['observations'] ?? null,
                'debit' => 0, 'credit' => 0, 'status' => 'posted', 'created_by' => Auth::id(),
            ]);
            $entry = new LedgerEntry($this->accounts, $contact);
            $concept = ['traslado' => 'Traslado entre bodegas', 'ajuste' => 'Ajuste de inventario', 'conteo' => 'Conteo físico', 'ensamble' => 'Ensamble', 'saldo' => 'Saldo inicial de inventario'][$kind];
            $moved = 0;

            foreach ($items as $idx => $i) {
                $p = $products->get($i['product_id']);
                if (! $p) {
                    continue;
                }
                $this->requireStockControl($p);
                $qty = (float) ($i['quantity'] ?? 0);
                switch ($kind) {
                    case 'traslado':
                        if ($qty <= 0 || empty($i['from_id']) || empty($i['to_id']) || (int) $i['from_id'] === (int) $i['to_id']) {
                            $this->fail("Línea {$p->code}: indica bodega de salida, bodega de entrada distinta y cantidad");
                        }
                        $this->inventory->move($p, (int) $i['from_id'], -$qty, $concept, $data['date'], null, $je, $name);
                        $this->inventory->move($p->refresh(), (int) $i['to_id'], $qty, $concept, $data['date'], null, $je, $name);
                        $moved++;
                        break;
                    case 'ajuste':
                    case 'saldo':
                        if ($kind === 'saldo' && ($qty <= 0 || (float) ($i['cost'] ?? 0) <= 0)) {
                            $this->fail("Línea {$p->code}: indica la cantidad y el costo unitario");
                        }
                        if ($qty <= 0 || ($kind === 'ajuste' && ! in_array($i['direction'] ?? '', ['+', '-'], true))) {
                            $this->fail("Línea {$p->code}: indica si aumenta o disminuye y la cantidad");
                        }
                        $plus = $kind === 'saldo' || $i['direction'] === '+';
                        $cost = (float) ($i['cost'] ?? 0) ?: (float) $p->average_cost;
                        $m = $this->inventory->move($p, $warehouse, $plus ? $qty : -$qty, $concept, $data['date'], $plus ? $cost : null, $je, $name);
                        $value = abs((float) ($m?->total_cost ?? 0));
                        if ($value > 0) {
                            $inv = $this->accounts->group($p->accountGroup, 'inventory');
                            $counter = ! empty($i['account_id']) ? (int) $i['account_id'] : $this->accounts->default($plus ? 'other_income' : 'other_expenses');
                            $plus ? $entry->debit($inv, $value)->credit($counter, $value) : $entry->debit($counter, $value)->credit($inv, $value);
                        }
                        $moved++;
                        break;
                    case 'conteo':
                        $counted = (float) ($i['counted'] ?? 0);
                        $current = (float) WarehouseStock::query()->where(['product_id' => $p->id, 'warehouse_id' => $warehouse])->value('quantity');
                        if (abs($counted - $current) > 0.0000001) {
                            $this->inventory->setBalance($p, $warehouse, $counted, $concept, $data['date'], $name);
                            $moved++;
                        }
                        break;
                    case 'ensamble':
                        if ($qty <= 0) {
                            $this->fail("Línea {$p->code}: indica la cantidad");
                        }
                        $moved++;
                        break;
                }
            }

            if ($kind === 'ensamble') {
                $this->assemble($items, $products, $warehouse, $data['date'], $je, $name, $entry);
            } elseif ($moved === 0) {
                $this->fail($kind === 'conteo' ? 'El conteo no tiene diferencias con el saldo actual' : 'Debes agregar al menos un producto con cantidad');
            }

            if (! $entry->isEmpty()) {
                $total = $entry->post($data['date'], $name, $type->type);
                $je->update(['debit' => $total, 'credit' => $total]);
            }

            return $je;
        });
    }

    /** El primer item es el producto que se fabrica; los demas son materias primas que salen al costo promedio. */
    private function assemble($items, $products, int $warehouse, string $date, JournalEntry $je, string $name, LedgerEntry $entry): void
    {
        if ($items->count() < 2) {
            $this->fail('El ensamble necesita el producto a fabricar y al menos una materia prima');
        }
        $target = $products->get($items[0]['product_id']);
        $total = 0.0;
        foreach ($items->slice(1) as $i) {
            $p = $products->get($i['product_id']);
            $m = $this->inventory->move($p, $warehouse, -(float) $i['quantity'], 'Ensamble (consumo)', $date, null, $je, $name);
            $value = abs((float) ($m?->total_cost ?? 0));
            $total += $value;
            $entry->credit($this->accounts->group($p->accountGroup, 'inventory'), $value);
        }
        $qty = (float) $items[0]['quantity'];
        $this->inventory->move($target->refresh(), $warehouse, $qty, 'Ensamble (producción)', $date, $total / $qty, $je, $name);
        $entry->debit($this->accounts->group($target->accountGroup, 'inventory'), $total);
    }

    private function requireStockControl(Product $p): void
    {
        if (! $p->stock_control) {
            $this->fail("El producto {$p->code} no maneja inventario");
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
