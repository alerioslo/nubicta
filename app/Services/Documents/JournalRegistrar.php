<?php

namespace App\Services\Documents;

use App\Models\Contact;
use App\Models\DocumentType;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Models\SalesDocument;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerEntry;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Comprobantes contables (CC), traslados de dinero (CC-9901) y ajustes de cartera / proveedor (CC-777). */
class JournalRegistrar
{
    public function __construct(private readonly Numbering $numbering, private readonly AccountResolver $accounts, private readonly InventoryService $inventory) {}

    public function journal(array $data): JournalEntry
    {
        return DB::transaction(fn () => $this->journalLines($data));
    }

    private function journalLines(array $data): JournalEntry
    {
        $type = $this->numbering->type($data['document_type'] ?? null, 'CC');
        $items = collect($data['items'] ?? [])->filter(fn ($l) => ! empty($l['account_id']) && ((float) ($l['debit'] ?? 0) + (float) ($l['credit'] ?? 0)) > 0)->values();
        $contacts = Contact::query()->whereIn('id', $items->pluck('contact_id')->filter())->get()->keyBy('id');
        $accounts = DB::table('accounts')->whereIn('id', $items->pluck('account_id'))->get(['id', 'code', 'due_type', 'requires_third_party'])->keyBy('id');
        $inventoryAccounts = DB::table('account_groups')->where('company_id', CurrentCompany::id())->whereIn('inventory_account_id', $accounts->keys())->pluck('inventory_account_id')->flip();
        $products = Product::query()->with('accountGroup')->whereIn('id', $items->pluck('inventory.product_id')->filter())->get()->keyBy('id');
        $lines = $items->map(function ($l, $k) use ($contacts, $accounts, $inventoryAccounts, $products) {
            $account = $accounts[(int) $l['account_id']] ?? throw ValidationException::withMessages(['items' => 'La línea '.($k + 1).' tiene una cuenta que no existe']);
            $contact = $contacts->get($l['contact_id'] ?? 0);
            if (($account->requires_third_party || $account->due_type) && ! $contact) {
                throw ValidationException::withMessages(['items' => 'Línea '.($k + 1).': el tercero no puede estar vacío para la cuenta '.$account->code]);
            }
            $line = ['account_id' => $account->id, 'contact' => $contact, 'description' => $l['description'] ?? null,
                'debit' => round((float) ($l['debit'] ?? 0), 2), 'credit' => round((float) ($l['credit'] ?? 0), 2)];
            if ($account->due_type) {
                $line += $this->dueDetail($l['due'] ?? null, $account->due_type, $contact, $line, $k + 1);
            }
            if ($inventoryAccounts->has($account->id)) {
                $line += $this->inventoryDetail($l['inventory'] ?? null, $products, $account, $line, $k + 1);
            }

            return $line;
        })->all();

        return $this->store($type, $data, $lines, null);
    }

    /**
     * "Detalle de cartera / proveedores" de Siigo: cruzar con un documento con saldo (lo abona o lo aumenta) o crear / modificar
     * un saldo con prefijo, numero, cuota y vencimiento.
     */
    private function dueDetail(?array $due, string $dueType, Contact $contact, array $line, int $n): array
    {
        if (! $due || empty($due['mode'])) {
            throw ValidationException::withMessages(['items' => "Línea $n: el detalle contable no puede estar vacío"]);
        }
        if ($due['mode'] === 'cruzar') {
            $model = $dueType === 'cartera' ? SalesDocument::class : PurchaseDocument::class;
            $doc = $model::query()->where('contact_id', $contact->id)->lockForUpdate()->find($due['document_id'] ?? 0)
                ?? throw ValidationException::withMessages(['items' => "Línea $n: selecciona el saldo a cruzar"]);
            $delta = round($dueType === 'cartera' ? $line['debit'] - $line['credit'] : $line['credit'] - $line['debit'], 2);
            if ((float) $doc->balance + $delta < -0.009) {
                throw ValidationException::withMessages(['items' => "Línea $n: el valor supera el saldo de {$doc->name}"]);
            }
            $doc->forceFill(['balance' => round((float) $doc->balance + $delta, 2)])->save();

            return ['due' => $doc];
        }
        $prefix = in_array($due['prefix'] ?? '', ['FV', 'FC', 'RC', 'RP', 'CC'], true) ? $due['prefix'] : 'CC';
        if (empty($due['number'])) {
            throw ValidationException::withMessages(['items' => "Línea $n: ingresa el número de comprobante del saldo"]);
        }

        return ['due_raw' => ['prefix' => $prefix, 'number' => (int) $due['number'], 'quote' => max(1, (int) ($due['quote'] ?? 1)), 'due_date' => $due['due_date'] ?? null]];
    }

    /** "Detalle de inventarios" de Siigo: la cuenta de un grupo de inventario mueve un producto; la entrada va al débito y la salida al crédito. */
    private function inventoryDetail(?array $detail, $products, object $account, array $line, int $n): array
    {
        $product = $products->get((int) ($detail['product_id'] ?? 0));
        $qty = round((float) ($detail['quantity'] ?? 0), 4);
        $type = $detail['type'] ?? '';
        if (! $product || $qty <= 0 || ! in_array($type, ['Entrada', 'Salida'], true)) {
            throw ValidationException::withMessages(['items' => "Línea $n: completa el detalle de inventarios (producto, cantidad y tipo de movimiento)"]);
        }
        if ((int) $product->accountGroup?->inventory_account_id !== (int) $account->id) {
            throw ValidationException::withMessages(['items' => "Línea $n: el producto {$product->code} no pertenece a un grupo de inventario con la cuenta {$account->code}"]);
        }
        if (! $product->stock_control) {
            throw ValidationException::withMessages(['items' => "Línea $n: el producto {$product->code} no maneja inventario"]);
        }
        if ($type === 'Entrada' ? $line['debit'] <= 0 : $line['credit'] <= 0) {
            throw ValidationException::withMessages(['items' => "Línea $n: ".($type === 'Entrada' ? 'la entrada de inventario va al débito' : 'la salida de inventario va al crédito')]);
        }

        return ['product' => $product, 'quantity' => $type === 'Entrada' ? $qty : -$qty];
    }

    public function moneyTransfer(array $data): JournalEntry
    {
        $type = $this->numbering->journalType('9901', 'Traslado de dinero');
        $contact = Contact::query()->find($data['contact_id'] ?? 0);
        $lines = [];
        foreach ($data['items'] ?? [] as $l) {
            $v = round((float) ($l['value'] ?? 0), 2);
            if ((int) $l['from_id'] === (int) $l['to_id']) {
                throw ValidationException::withMessages(['items' => 'La cuenta de salida y la de entrada deben ser diferentes']);
            }
            $lines[] = ['account_id' => (int) $l['to_id'], 'contact' => $contact, 'description' => 'Traslado de dinero', 'debit' => $v, 'credit' => 0];
            $lines[] = ['account_id' => (int) $l['from_id'], 'contact' => $contact, 'description' => 'Traslado de dinero', 'debit' => 0, 'credit' => $v];
        }

        return $this->store($type, $data, $lines, 'money_transfer');
    }

    public function portfolioAdjustment(array $data): JournalEntry
    {
        $module = $data['module'] ?? '';
        if (! in_array($module, ['ventas', 'compras'], true)) {
            throw ValidationException::withMessages(['module' => 'Selecciona el tipo de cuenta']);
        }
        $type = $this->numbering->journalType('777', 'Ajustes contables de cartera');
        $contact = Contact::query()->findOrFail($data['contact_id']);
        $model = $module === 'ventas' ? SalesDocument::class : PurchaseDocument::class;
        $doc = $model::query()->where('contact_id', $contact->id)->find($data['document_id'] ?? 0)
            ?? throw ValidationException::withMessages(['document_id' => 'Selecciona el documento a ajustar']);
        $items = collect($data['items'] ?? [])->filter(fn ($i) => ! empty($i['concept']) && (float) ($i['value'] ?? 0) != 0);
        $total = round($items->sum(fn ($i) => (float) $i['value']), 2);
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Agrega al menos un concepto con valor']);
        }
        if ($total - (float) $doc->balance > 0.009) {
            throw ValidationException::withMessages(['items' => 'El valor de los ajustes supera el saldo del documento']);
        }

        $counter = $this->accounts->default($module === 'ventas' ? 'customers' : 'suppliers');
        $lines = [];
        foreach ($items as $i) {
            $v = round((float) $i['value'], 2);
            $account = $this->accounts->id((string) config("accounting.portfolio_concepts.$module.{$i['concept']}", config('accounting.defaults.other_expenses')));
            $desc = trim($i['concept'].' '.($i['detail'] ?? ''));
            $lines[] = ['account_id' => $account, 'contact' => $contact, 'description' => $desc, 'debit' => $module === 'ventas' ? $v : 0, 'credit' => $module === 'ventas' ? 0 : $v];
        }
        $lines[] = ['account_id' => $counter, 'contact' => $contact, 'description' => 'Ajuste '.$doc->name, 'debit' => $module === 'ventas' ? 0 : $total,
            'credit' => $module === 'ventas' ? $total : 0, 'due' => $doc];

        return DB::transaction(function () use ($type, $data, $lines, $doc, $total) {
            $entry = $this->store($type, $data, $lines, 'portfolio_adjustment', $doc);
            $doc->forceFill(['balance' => round((float) $doc->balance - $total, 2)])->save();

            return $entry;
        });
    }

    /** @param list<array{account_id:int, contact:?Contact, description:?string, debit:float, credit:float, due?:mixed}> $lines */
    public function store(DocumentType $type, array $data, array $lines, ?string $sourceType, mixed $source = null): JournalEntry
    {
        $this->numbering->assertOpen($data['date']);
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);
        if ($debit <= 0 || abs($debit - $credit) > 0.009) {
            throw ValidationException::withMessages(['items' => 'El comprobante debe estar cuadrado: débitos y créditos iguales y mayores a cero']);
        }

        return DB::transaction(function () use ($type, $data, $lines, $sourceType, $source, $debit, $credit) {
            [$number, $name] = $this->numbering->take($type, 'journal_entries');
            $je = JournalEntry::create([
                'company_id' => CurrentCompany::id(), 'source' => 'erp', 'document_type_id' => $type->id,
                'source_type' => $sourceType, 'source_id' => $source?->id, 'number' => $number, 'name' => $name, 'date' => $data['date'],
                'observations' => $data['observations'] ?? null, 'debit' => $debit, 'credit' => $credit, 'status' => 'posted', 'created_by' => Auth::id(),
            ]);
            $entry = new LedgerEntry($this->accounts);
            foreach ($lines as $l) {
                $due = $l['due'] ?? null;
                $raw = $l['due_raw'] ?? null;
                $je->lines()->create([
                    'company_id' => CurrentCompany::id(), 'date' => $data['date'], 'account_id' => $l['account_id'], 'contact_id' => $l['contact']?->id,
                    'description' => $l['description'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'cost_center_id' => $l['cost_center_id'] ?? null,
                    'due_prefix' => $due ? preg_replace('/-\d+$/', '', $due->name) : $raw['prefix'] ?? null,
                    'due_number' => $due?->number ?? $raw['number'] ?? null, 'due_date' => $due ? ($due->due_date ?? $due->date) : $raw['due_date'] ?? null,
                    'product_id' => isset($l['product']) ? $l['product']->id : null, 'quantity' => $l['quantity'] ?? null,
                ]);
                $entry->add($l['account_id'], $l['debit'], $l['credit'], $l['contact']);
                if (isset($l['product'])) {
                    $warehouse ??= (int) Warehouse::query()->where('active', true)->orderBy('id')->value('id');
                    $this->inventory->move($l['product']->refresh(), $warehouse, $l['quantity'], 'Comprobante contable', $data['date'],
                        $l['quantity'] > 0 ? $l['debit'] / $l['quantity'] : null, $je, $name);
                }
            }
            $entry->post($data['date'], $name, 'CC');

            return $je;
        });
    }
}
