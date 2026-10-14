<?php

namespace App\Services\Siigo\Importers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Models\PurchaseDocumentPayment;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\SiigoImportError;
use App\Services\Siigo\Importers\Concerns\ResolvesContacts;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Compras (v1/purchases), recibos de caja (v1/vouchers), recibos de pago (v1/payment-receipts) y comprobantes (v1/journals). */
class OtherDocumentsImporter extends BaseImporter
{
    use ResolvesContacts;

    private const WITHHOLDING_TYPES = ['Retefuente', 'ReteIVA', 'ReteICA', 'Autorretencion'];

    /** @var array<string, int> */
    private array $products = [];

    /** @var array<string, int> */
    private array $accounts = [];

    public function entity(): string
    {
        return 'documentos';
    }

    protected function handle(array $params): void
    {
        $this->products = Product::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('id', 'code')->all();
        $this->accounts = Account::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('id', 'code')->all();

        $query = array_filter([
            'created_start' => $params['desde'] ?? null,
            'created_end' => isset($params['hasta']) ? date('Y-m-d', strtotime($params['hasta'].' +1 day')) : null,
        ]);

        $sets = [
            'v1/purchases' => fn ($r) => $this->purchase($r),
            'v1/vouchers' => fn ($r) => $this->receipt($r, 'RC'),
            'v1/payment-receipts' => fn ($r) => $this->receipt($r, 'RP'),
            'v1/journals' => fn ($r) => $this->journal($r),
        ];
        $only = $params['solo'] ?? null;
        foreach ($sets as $endpoint => $fn) {
            if ($only && ! str_contains($endpoint, $only)) {
                continue;
            }
            $this->log("$endpoint...");
            $n = 0;
            foreach ($this->siigo->paginate($endpoint, $query) as $r) {
                $this->safely($r, $fn);
                if (++$n % 1000 === 0) {
                    $this->log("  $n...");
                }
            }
        }
    }

    private function safely(array $r, Closure $fn): void
    {
        try {
            DB::transaction(fn () => $fn($r));
        } catch (Throwable $e) {
            $this->run->increment('failed');
            SiigoImportError::create(['run_id' => $this->run->id, 'siigo_id' => $r['id'] ?? null, 'error' => mb_substr($e->getMessage(), 0, 3000), 'payload' => $r]);
            $this->log('  error '.($r['name'] ?? '?').': '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    private function purchase(array $r): void
    {
        $doc = $this->upsert(PurchaseDocument::class, 'purchase_document', $r, function ($r) {
            $subtotal = $discount = $tax = $withholding = 0;
            foreach ($r['items'] ?? [] as $i) {
                $d = (float) ($i['discount'] ?? 0);
                $subtotal += round((float) $i['quantity'] * (float) $i['price'] - $d, 2);
                $discount += $d;
                foreach ($i['taxes'] ?? [] as $t) {
                    in_array($t['type'] ?? '', self::WITHHOLDING_TYPES, true) ? $withholding += (float) $t['value'] : $tax += (float) $t['value'];
                }
            }
            foreach ($r['retentions'] ?? [] as $t) {
                $withholding += (float) ($t['value'] ?? 0);
            }

            return [
                'siigo_id' => $r['id'],
                'source' => 'siigo',
                'type' => 'FC',
                'document_type_id' => $this->localId('document_type', $r['document']['id'] ?? null),
                'number' => (int) $r['number'],
                'name' => $r['name'],
                'date' => $r['date'],
                'due_date' => collect($r['payments'] ?? [])->pluck('due_date')->filter()->max(),
                'contact_id' => $this->contactId($r['supplier'] ?? [], 'is_supplier'),
                'supplier_prefix' => $r['provider_invoice']['prefix'] ?? null,
                'supplier_number' => isset($r['provider_invoice']['number']) ? (string) $r['provider_invoice']['number'] : null,
                'cost_center_id' => $this->localId('cost_center', $r['cost_center'] ?? null),
                'currency' => $r['currency']['code'] ?? 'COP',
                'subtotal' => $subtotal, 'discount' => $discount, 'tax_total' => $tax, 'withholding_total' => $withholding,
                'total' => (float) ($r['total'] ?? 0),
                'balance' => (float) ($r['balance'] ?? 0),
                'observations' => ($r['observations'] ?? '') ?: null,
                'created_at' => isset($r['metadata']['created']) ? substr($r['metadata']['created'], 0, 19) : now(),
            ];
        });
        if (! $doc) {
            return;
        }

        PurchaseDocumentItem::where('purchase_document_id', $doc->id)->delete();
        foreach (array_values($r['items'] ?? []) as $pos => $i) {
            $type = $i['type'] ?? 'Product';
            $code = trim((string) ($i['code'] ?? ''));
            $taxes = $i['taxes'] ?? [];
            $d = (float) ($i['discount'] ?? 0);
            $taxValue = collect($taxes)->reject(fn ($t) => in_array($t['type'] ?? '', self::WITHHOLDING_TYPES, true))->sum('value');
            $item = PurchaseDocumentItem::create([
                'purchase_document_id' => $doc->id,
                'position' => $pos,
                'item_type' => $type,
                'product_id' => $type === 'Product' ? ($this->products[$code] ?? null) : null,
                'account_id' => $type === 'Account' ? ($this->accounts[$code] ?? null) : null,
                'code' => $code ?: null,
                'description' => mb_substr((string) ($i['description'] ?? $code), 0, 500),
                'warehouse_id' => $this->localId('warehouse', $i['warehouse'] ?? null),
                'quantity' => (float) $i['quantity'],
                'price' => (float) $i['price'],
                'discount' => $d,
                'subtotal' => round((float) $i['quantity'] * (float) $i['price'] - $d, 2),
                'tax_total' => $taxValue,
                'total' => (float) ($i['total'] ?? 0),
                'tax_percentage' => collect($taxes)->firstWhere('type', 'IVA')['percentage'] ?? null,
            ]);
            if ($taxes) {
                DB::table('purchase_document_item_taxes')->insert(array_map(fn ($t) => [
                    'purchase_document_item_id' => $item->id, ...$this->taxRow($t),
                ], $taxes));
            }
        }

        DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->delete();
        if ($ret = $r['retentions'] ?? []) {
            DB::table('purchase_document_retentions')->insert(array_map(fn ($t) => ['purchase_document_id' => $doc->id, ...$this->taxRow($t)], $ret));
        }

        PurchaseDocumentPayment::where('purchase_document_id', $doc->id)->delete();
        foreach ($r['payments'] ?? [] as $p) {
            PurchaseDocumentPayment::create([
                'purchase_document_id' => $doc->id,
                'payment_method_id' => $this->localId('payment_method', $p['id'] ?? null),
                'name' => mb_substr((string) ($p['name'] ?? ''), 0, 120) ?: null,
                'value' => (float) ($p['value'] ?? 0),
                'due_date' => $p['due_date'] ?? null,
            ]);
        }
    }

    private function receipt(array $r, string $type): void
    {
        $isCustomer = $type === 'RC';
        $doc = $this->upsert(Receipt::class, 'receipt', $r, fn ($r) => [
            'siigo_id' => $r['id'],
            'source' => 'siigo',
            'type' => $type,
            'receipt_type' => $r['type'] ?? 'DebtPayment',
            'document_type_id' => $this->localId('document_type', $r['document']['id'] ?? null),
            'number' => (int) $r['number'],
            'name' => $r['name'],
            'date' => $r['date'],
            'contact_id' => $this->contactId(($isCustomer ? $r['customer'] : $r['supplier']) ?? [], $isCustomer ? 'is_customer' : 'is_supplier'),
            'payment_method_id' => $this->localId('payment_method', $r['payment']['id'] ?? null),
            'payment_name' => mb_substr((string) ($r['payment']['name'] ?? ''), 0, 120) ?: null,
            'total' => (float) ($r['payment']['value'] ?? collect($r['items'] ?? [])->sum('value')),
            'observations' => ($r['observations'] ?? '') ?: null,
            'status' => ($r['annulled'] ?? false) ? 'annulled' : 'issued',
            'created_at' => isset($r['metadata']['created']) ? substr($r['metadata']['created'], 0, 19) : now(),
        ]);
        if (! $doc) {
            return;
        }

        ReceiptItem::where('receipt_id', $doc->id)->delete();
        foreach ($r['items'] ?? [] as $i) {
            $due = $i['due'] ?? [];
            $prefix = $due['prefix'] ?? null;
            $number = isset($due['consecutive']) ? (int) $due['consecutive'] : null;
            ReceiptItem::create([
                'receipt_id' => $doc->id,
                'due_prefix' => $prefix,
                'due_number' => $number,
                'due_quote' => (int) ($due['quote'] ?? 1),
                'due_date' => $due['date'] ?? null,
                'sales_document_id' => $isCustomer && $prefix ? $this->salesDocumentId("$prefix-$number") : null,
                'purchase_document_id' => ! $isCustomer && $prefix ? $this->purchaseDocumentId($prefix, $number, $doc->contact_id) : null,
                'account_id' => isset($i['account']['code']) ? ($this->accounts[$i['account']['code']] ?? null) : null,
                'description' => mb_substr((string) ($i['description'] ?? ''), 0, 255) ?: null,
                'value' => (float) ($i['value'] ?? 0),
            ]);
        }
    }

    private function journal(array $r): void
    {
        $entry = $this->upsert(JournalEntry::class, 'journal_entry', $r, function ($r) {
            $debit = $credit = 0;
            foreach ($r['items'] ?? [] as $i) {
                ($i['account']['movement'] ?? 'Debit') === 'Debit' ? $debit += (float) $i['value'] : $credit += (float) $i['value'];
            }

            return [
                'siigo_id' => $r['id'],
                'source' => 'siigo',
                'document_type_id' => $this->localId('document_type', $r['document']['id'] ?? null),
                'number' => (int) $r['number'],
                'name' => $r['name'],
                'date' => $r['date'],
                'observations' => ($r['observations'] ?? '') ?: null,
                'debit' => $debit,
                'credit' => $credit,
                'created_at' => isset($r['metadata']['created']) ? substr($r['metadata']['created'], 0, 19) : now(),
            ];
        });
        if (! $entry) {
            return;
        }

        JournalLine::where('journal_entry_id', $entry->id)->delete();
        $rows = [];
        foreach ($r['items'] ?? [] as $i) {
            $code = (string) ($i['account']['code'] ?? '');
            $accountId = $this->accounts[$code] ?? throw new RuntimeException("Cuenta $code no existe en el PUC local");
            $value = (float) ($i['value'] ?? 0);
            $isDebit = ($i['account']['movement'] ?? 'Debit') === 'Debit';
            $rows[] = [
                'journal_entry_id' => $entry->id,
                'company_id' => $this->companyId,
                'date' => $r['date'],
                'account_id' => $accountId,
                'contact_id' => isset($i['customer']) ? $this->contactId($i['customer'], 'is_other') : null,
                'cost_center_id' => $this->localId('cost_center', $i['cost_center'] ?? null),
                'product_id' => isset($i['product']['code']) ? ($this->products[$i['product']['code']] ?? null) : null,
                'quantity' => $i['product']['quantity'] ?? null,
                'description' => mb_substr((string) ($i['description'] ?? ''), 0, 255) ?: null,
                'debit' => $isDebit ? $value : 0,
                'credit' => $isDebit ? 0 : $value,
                'due_prefix' => $i['due']['prefix'] ?? null,
                'due_number' => $i['due']['consecutive'] ?? null,
                'due_date' => $i['due']['date'] ?? null,
            ];
        }
        JournalLine::insert($rows);
    }

    private function taxRow(array $t): array
    {
        return [
            'tax_id' => $this->localId('tax', $t['id'] ?? null),
            'name' => mb_substr((string) ($t['name'] ?? ''), 0, 80),
            'type' => (string) ($t['type'] ?? ''),
            'percentage' => (float) ($t['percentage'] ?? 0),
            'value' => (float) ($t['value'] ?? 0),
        ];
    }

    private function salesDocumentId(string $name): ?int
    {
        return DB::table('sales_documents')->where(['company_id' => $this->companyId, 'name' => $name])->value('id');
    }

    /** El vencimiento de proveedores llega como "FC-1"+consecutivo o como prefijo+numero de la factura del proveedor. */
    private function purchaseDocumentId(string $prefix, ?int $number, int $contactId): ?int
    {
        return DB::table('purchase_documents')->where('company_id', $this->companyId)
            ->where(fn ($q) => $q->where('name', "$prefix-$number")
                ->orWhere(fn ($w) => $w->where('contact_id', $contactId)->where('supplier_prefix', $prefix)->where('supplier_number', (string) $number)))
            ->value('id');
    }
}
