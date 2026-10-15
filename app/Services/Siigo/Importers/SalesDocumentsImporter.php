<?php

namespace App\Services\Siigo\Importers;

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentItem;
use App\Models\SalesDocumentItemTax;
use App\Models\SalesDocumentPayment;
use App\Models\SiigoImportError;
use App\Services\Siigo\Importers\Concerns\ResolvesContacts;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Facturas (v1/invoices), notas credito (v1/credit-notes) y cotizaciones (v1/quotations) como documentos externos.
 * No mueven el kardex: el saldo de inventario ya viene de Siigo en la importacion de productos.
 */
class SalesDocumentsImporter extends BaseImporter
{
    use ResolvesContacts;

    private const WITHHOLDING_TYPES = ['Retefuente', 'ReteIVA', 'ReteICA', 'Autorretencion'];

    /** @var array<string, int> */
    private array $products = [];

    public function entity(): string
    {
        return 'ventas';
    }

    protected function handle(array $params): void
    {
        $this->products = Product::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('id', 'code')->all();

        /* date_start/date_end de Siigo no devuelve los dias recientes; created_* si (igual que el modulo de deposito) */
        $query = array_filter([
            'created_start' => $params['desde'] ?? null,
            'created_end' => isset($params['hasta']) ? date('Y-m-d', strtotime($params['hasta'].' +1 day')) : null, // Siigo lo toma como 00:00
        ]);

        foreach (['v1/invoices' => 'FV', 'v1/credit-notes' => 'NC', 'v1/quotations' => 'CT'] as $endpoint => $type) {
            $this->log("$endpoint...");
            $n = 0;
            foreach ($this->siigo->paginate($endpoint, $query) as $r) {
                $this->importDocument($r, $type);
                if (++$n % 1000 === 0) {
                    $this->log("  $n...");
                }
            }
        }
    }

    private function importDocument(array $r, string $type): void
    {
        try {
            DB::transaction(function () use ($r, $type) {
                $doc = $this->upsert(SalesDocument::class, 'sales_document', $r, fn ($r) => $this->map($r, $type));
                if ($doc) {
                    $this->syncItems($doc, $r['items'] ?? []);
                    $this->syncPayments($doc, $r['payments'] ?? []);
                }
            });
        } catch (Throwable $e) {
            $this->run->increment('failed');
            SiigoImportError::create(['run_id' => $this->run->id, 'siigo_id' => $r['id'] ?? null, 'error' => mb_substr($e->getMessage(), 0, 3000), 'payload' => $r]);
            $this->log("  error {$r['name']}: ".mb_substr($e->getMessage(), 0, 200));
        }
    }

    private function map(array $r, string $type): array
    {
        $taxTotal = 0;
        $withholding = 0;
        $subtotal = 0;
        $discount = 0;
        foreach ($r['items'] ?? [] as $i) {
            $lineDiscount = $this->discountValue($i);
            $subtotal += round((float) $i['quantity'] * (float) $i['price'] - $lineDiscount, 2);
            $discount += $lineDiscount;
            foreach ($i['taxes'] ?? [] as $t) {
                in_array($t['type'] ?? '', self::WITHHOLDING_TYPES, true)
                    ? $withholding += (float) ($t['value'] ?? 0)
                    : $taxTotal += (float) ($t['value'] ?? 0);
            }
        }

        $stamp = $r['stamp'] ?? [];

        return [
            'siigo_id' => $r['id'],
            'source' => 'siigo',
            'type' => $type,
            'document_type_id' => $this->localId('document_type', $r['document']['id'] ?? null),
            'prefix' => $r['prefix'] ?? ($r['invoice_data']['prefix'] ?? null),
            'number' => (int) $r['number'],
            'name' => $r['name'],
            'date' => $r['date'],
            'due_date' => collect($r['payments'] ?? [])->pluck('due_date')->filter()->max(),
            'contact_id' => $this->contactId($r['customer'] ?? []),
            'seller_id' => $this->localId('seller', $r['seller'] ?? null),
            'cost_center_id' => $this->localId('cost_center', $r['cost_center'] ?? null),
            'related_document_id' => $type === 'NC' ? $this->localId('sales_document', $r['invoice']['id'] ?? null) : null,
            'related_name' => $r['invoice']['name'] ?? null,
            'reason' => isset($r['reason']) ? (string) $r['reason'] : null,
            'currency' => $r['currency']['code'] ?? 'COP',
            'exchange_rate' => $r['currency']['exchange_rate'] ?? 1,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_total' => $taxTotal,
            'withholding_total' => $withholding,
            'total' => (float) ($r['total'] ?? 0),
            'balance' => (float) ($r['balance'] ?? 0),
            'observations' => ($r['observations'] ?? '') ?: null,
            'status' => ($r['annulled'] ?? false) ? 'annulled' : 'issued',
            'cufe' => $stamp['cufe'] ?? ($stamp['cude'] ?? null),
            'dian_status' => $stamp['status'] ?? null,
            'mail_status' => $r['mail']['status'] ?? null,
            'public_url' => $r['public_url'] ?? null,
            'created_at' => isset($r['metadata']['created']) ? substr($r['metadata']['created'], 0, 19) : now(),
        ];
    }

    private function syncItems(SalesDocument $doc, array $items): void
    {
        SalesDocumentItem::where('sales_document_id', $doc->id)->delete();
        foreach (array_values($items) as $pos => $i) {
            $taxes = $i['taxes'] ?? [];
            $lineDiscount = $this->discountValue($i);
            $taxValue = 0;
            $mainPct = null;
            foreach ($taxes as $t) {
                if (! in_array($t['type'] ?? '', self::WITHHOLDING_TYPES, true)) {
                    $taxValue += (float) ($t['value'] ?? 0);
                    if (($t['type'] ?? '') === 'IVA' && $mainPct === null) {
                        $mainPct = (float) ($t['percentage'] ?? 0);
                    }
                }
            }
            $code = trim((string) ($i['code'] ?? ''));
            $item = SalesDocumentItem::create([
                'sales_document_id' => $doc->id,
                'position' => $pos,
                'product_id' => $this->products[$code] ?? null,
                'code' => $code ?: null,
                'description' => mb_substr((string) ($i['description'] ?? $code), 0, 500),
                'warehouse_id' => $this->localId('warehouse', $i['warehouse'] ?? null),
                'seller_id' => $this->localId('seller', $i['seller'] ?? null),
                'quantity' => (float) $i['quantity'],
                'price' => (float) $i['price'],
                'discount' => $lineDiscount,
                'subtotal' => round((float) $i['quantity'] * (float) $i['price'] - $lineDiscount, 2),
                'tax_total' => $taxValue,
                'total' => (float) ($i['total'] ?? 0),
                'tax_percentage' => $mainPct,
            ]);
            if ($taxes) {
                SalesDocumentItemTax::insert(array_map(fn ($t) => [
                    'sales_document_item_id' => $item->id,
                    'tax_id' => $this->localId('tax', $t['id'] ?? null),
                    'name' => mb_substr((string) ($t['name'] ?? ''), 0, 80),
                    'type' => (string) ($t['type'] ?? ''),
                    'percentage' => (float) ($t['percentage'] ?? 0),
                    'value' => (float) ($t['value'] ?? 0),
                ], $taxes));
            }
        }
    }

    private function syncPayments(SalesDocument $doc, array $payments): void
    {
        SalesDocumentPayment::where('sales_document_id', $doc->id)->delete();
        if ($payments) {
            SalesDocumentPayment::insert(array_map(fn ($p) => [
                'sales_document_id' => $doc->id,
                'payment_method_id' => $this->localId('payment_method', $p['id'] ?? null),
                'name' => mb_substr((string) ($p['name'] ?? ''), 0, 120) ?: null,
                'value' => (float) ($p['value'] ?? 0),
                'due_date' => $p['due_date'] ?? null,
            ], $payments));
        }
    }

}
