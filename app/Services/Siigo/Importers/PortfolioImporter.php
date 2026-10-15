<?php

namespace App\Services\Siigo\Importers;

use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Saldos de cartera y proveedores como los calcula Siigo (por vencimiento contable, no por el campo balance del documento):
 * - Proveedores: v1/accounts-payable es la fuente exacta; lo que no casa con una compra queda como documento SI.
 * - Clientes: saldo de la factura en la API (se refresca la que tocaron recibos o notas nuevas), vencimientos creados por
 *   comprobantes contables (CC-xxxx) como documentos SI y notas credito a credito sin cruzar como saldo a favor.
 * Saldo negativo = valor a favor del tercero.
 */
class PortfolioImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'cartera';
    }

    protected function handle(array $params): void
    {
        $since = $params['desde'] ?? now()->subDays(2)->toDateString();
        $this->payables();
        $this->refreshInvoices($since, ! empty($params['completo']));
        $this->voucherDues();
        $this->creditNotes();
    }

    /** Aplica el saldo que reporta Siigo respetando lo que se haya abonado desde el ERP despues de la ultima sincronizacion. */
    public static function applyBalance(Model $doc, float $siigo): bool
    {
        $siigo = round($siigo, 2);
        $synced = $doc->synced_balance;
        $balance = $synced === null ? $siigo : round((float) $doc->balance + $siigo - (float) $synced, 2);
        if ($synced !== null && (float) $synced === $siigo && (float) $doc->balance === $balance) {
            return false;
        }
        $doc->forceFill(['balance' => $balance, 'synced_balance' => $siigo])->save();

        return true;
    }

    private function payables(): void
    {
        $this->log('v1/accounts-payable...');
        $contacts = DB::table('contacts')->where('company_id', $this->companyId)->whereNotNull('siigo_id')->pluck('id', 'siigo_id')->all();
        $byIdentification = DB::table('contacts')->where('company_id', $this->companyId)->orderBy('id')->pluck('id', 'identification')->all();

        $dues = [];
        foreach ($this->siigo->paginate('v1/accounts-payable') as $r) {
            $this->run->increment('fetched');
            $contactId = $contacts[$r['provider']['id'] ?? ''] ?? $byIdentification[$r['provider']['identification'] ?? ''] ?? null;
            if (! $contactId) {
                $this->run->increment('failed');
                $this->log("  sin tercero {$r['provider']['identification']}");

                continue;
            }
            $key = $contactId.'|'.$r['due']['prefix'].'|'.$r['due']['consecutive'];
            $dues[$key] ??= ['contact_id' => $contactId, 'prefix' => (string) $r['due']['prefix'], 'number' => (int) $r['due']['consecutive'], 'date' => $r['due']['date'] ?? null, 'balance' => 0.0];
            $dues[$key]['balance'] += (float) $r['due']['balance'];
            $dues[$key]['date'] = max($dues[$key]['date'], $r['due']['date'] ?? null);
        }

        $seen = [];
        foreach ($dues as $d) {
            try {
                DB::transaction(function () use ($d, &$seen) {
                    $docs = PurchaseDocument::withoutGlobalScopes()->where('company_id', $this->companyId)->where('contact_id', $d['contact_id'])
                        ->where('status', '!=', 'annulled')
                        ->where(fn ($q) => $q->where(fn ($w) => $w->where('supplier_prefix', $d['prefix'])->where('supplier_number', (string) $d['number']))
                            ->orWhere('name', "{$d['prefix']}-{$d['number']}"))
                        ->orderByRaw("type = 'SI'")->orderBy('id')->get();
                    $doc = $docs->first() ?? $this->openingDocument(PurchaseDocument::class, $d, ['supplier_prefix' => $d['prefix'], 'supplier_number' => (string) $d['number']]);
                    $seen[$doc->id] = true;
                    $this->count(self::applyBalance($doc, $d['balance']));
                    foreach ($docs->slice(1) as $dup) {
                        $seen[$dup->id] = true;
                        $this->count(self::applyBalance($dup, 0));
                    }
                });
            } catch (Throwable $e) {
                $this->run->increment('failed');
                $this->log("  error {$d['prefix']}-{$d['number']}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        PurchaseDocument::withoutGlobalScopes()->where('company_id', $this->companyId)->where('source', 'like', 'siigo%')
            ->where('status', '!=', 'annulled')
            ->where(fn ($q) => $q->where('balance', '!=', 0)->orWhere('synced_balance', '!=', 0))
            ->whereNotIn('id', array_keys($seen) ?: [0])
            ->each(fn ($doc) => $this->count(self::applyBalance($doc, 0)));
        $this->log('  '.count($dues).' vencimientos de proveedores');
    }

    /** Facturas tocadas por recibos o notas creados en Siigo desde $since (o todas las que tienen saldo con --completo). */
    private function refreshInvoices(string $since, bool $all): void
    {
        $ids = $all
            ? DB::table('sales_documents')->where('company_id', $this->companyId)->where('type', 'FV')->where('source', 'siigo')->where('balance', '!=', 0)->pluck('id')
            : DB::table('receipt_items')->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
                ->where('receipts.company_id', $this->companyId)->where('receipts.created_at', '>=', $since)->whereNotNull('receipt_items.sales_document_id')
                ->pluck('receipt_items.sales_document_id')
                ->merge(DB::table('sales_documents')->where('company_id', $this->companyId)->where('type', 'NC')->where('created_at', '>=', $since)->whereNotNull('related_document_id')->pluck('related_document_id'))
                ->merge(DB::table('sales_documents as n')->join('sales_documents as f', fn ($j) => $j->on('f.name', '=', 'n.related_name')->on('f.company_id', '=', 'n.company_id'))
                    ->where('n.company_id', $this->companyId)->where('n.type', 'NC')->where('n.created_at', '>=', $since)->pluck('f.id'));
        $docs = SalesDocument::withoutGlobalScopes()->whereIn('id', $ids->unique()->all())->where('type', 'FV')->whereNotNull('siigo_id')->get();
        $this->log("v1/invoices: {$docs->count()} facturas por refrescar...");
        foreach ($docs as $doc) {
            try {
                $r = $this->siigo->get("v1/invoices/{$doc->siigo_id}");
                $this->run->increment('fetched');
                if (array_key_exists('balance', $r)) {
                    $this->count(self::applyBalance($doc, (float) $r['balance']));
                }
            } catch (Throwable $e) {
                $this->run->increment('failed');
                $this->log("  error {$doc->name}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }
    }

    /** Vencimientos de cartera creados por comprobantes contables (saldos iniciales CC-999, ajustes...). */
    private function voucherDues(): void
    {
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $this->companyId)->where('a.due_type', 'cartera')->where('a.code', 'like', '13%')->where('e.status', '!=', 'annulled')
            ->whereNotNull('l.due_number')->whereNotNull('l.due_prefix')->whereNotNull('l.contact_id')
            ->groupBy('l.contact_id', 'l.due_prefix', 'l.due_number')
            ->selectRaw('l.contact_id, l.due_prefix, l.due_number, SUM(l.debit) AS debit, SUM(l.debit - l.credit) AS net, MIN(e.date) AS date, MAX(l.due_date) AS due_date, MIN(e.name) AS entry')
            ->get();
        $documents = DB::table('sales_documents')->where('company_id', $this->companyId)->whereIn('type', ['FV', 'NC', 'ND'])->pluck('id', 'name')->all();
        $n = 0;
        foreach ($rows as $r) {
            $name = "{$r->due_prefix}-{$r->due_number}";
            if (isset($documents[$name])) {
                continue;
            }
            $doc = SalesDocument::withoutGlobalScopes()->where('company_id', $this->companyId)->where('type', 'SI')->where('source', 'siigo')
                ->where('contact_id', $r->contact_id)->where('prefix', $r->due_prefix)->where('number', $r->due_number)->first();
            $applied = (float) DB::table('receipt_items')->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
                ->where('receipts.status', '!=', 'annulled')->where('receipts.type', 'RC')->where('receipts.contact_id', $r->contact_id)
                ->where(fn ($q) => $q->when($doc, fn ($w) => $w->where('receipt_items.sales_document_id', $doc->id))
                    ->orWhere(fn ($w) => $w->whereNull('receipt_items.sales_document_id')->where('receipt_items.due_prefix', $r->due_prefix)->where('receipt_items.due_number', $r->due_number)))
                ->sum(DB::raw('receipt_items.value + receipt_items.adjustments'));
            $balance = round((float) $r->net - $applied, 2);
            if (! $doc && abs($balance) < 0.005) {
                continue;
            }
            $doc ??= $this->openingDocument(SalesDocument::class, ['contact_id' => $r->contact_id, 'prefix' => $r->due_prefix, 'number' => (int) $r->due_number,
                'date' => $r->date, 'due_date' => $r->due_date, 'balance' => (float) $r->debit, 'related_name' => $r->entry], ['prefix' => $r->due_prefix]);
            if ((float) $doc->balance !== $balance) {
                $doc->forceFill(['balance' => $balance])->save();
                $this->run->increment('updated');
            }
            DB::table('receipt_items')->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')->where('receipts.contact_id', $r->contact_id)->where('receipts.type', 'RC')
                ->where('receipt_items.due_prefix', $r->due_prefix)->where('receipt_items.due_number', $r->due_number)->whereNull('receipt_items.sales_document_id')
                ->update(['receipt_items.sales_document_id' => $doc->id]);
            $n++;
        }
        $this->log("  $n vencimientos de comprobantes contables");
    }

    /** Notas credito con forma de pago a credito: lo que no se ha cruzado queda como valor a favor del cliente (saldo negativo). */
    private function creditNotes(): void
    {
        $credit = DB::table('sales_document_payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->join('accounts as a', 'a.id', '=', 'm.account_id')
            ->join('sales_documents as n', 'n.id', '=', 'p.sales_document_id')
            ->where('n.company_id', $this->companyId)->where('n.type', 'NC')->where('n.source', 'siigo')->where('n.status', '!=', 'annulled')->where('a.due_type', 'cartera')
            ->groupBy('n.id')->selectRaw('n.id, SUM(p.value) AS v')->pluck('v', 'id');
        $n = 0;
        foreach (SalesDocument::withoutGlobalScopes()->whereIn('id', $credit->keys()->all())->get() as $nc) {
            $used = (float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.contact_id', $nc->contact_id)->where('a.code', 'like', '13%')->where('e.status', '!=', 'annulled')
                ->where('l.due_prefix', preg_replace('/-\d+$/', '', $nc->name))->where('l.due_number', $nc->number)
                ->sum(DB::raw('l.debit - l.credit'));
            $balance = -max(0, round((float) $credit[$nc->id] - $used, 2));
            if ((float) $nc->balance !== $balance) {
                $nc->forceFill(['balance' => $balance])->save();
                $this->run->increment('updated');
                $n++;
            }
        }
        $this->log("  $n notas crédito con saldo a favor actualizadas");
    }

    /** Documento SI para un vencimiento que no corresponde a ningun documento importado (saldo inicial o comprobante contable). */
    private function openingDocument(string $model, array $d, array $extra): Model
    {
        $name = substr("{$d['prefix']}-{$d['number']}", 0, 40);
        if ($model::withoutGlobalScopes()->where('company_id', $this->companyId)->where('name', $name)->exists()) {
            $name = substr("$name-{$d['contact_id']}", 0, 40);
        }
        $value = abs(round((float) $d['balance'], 2));
        $this->run->increment('created');

        return $model::withoutGlobalScopes()->create([
            'company_id' => $this->companyId, 'source' => 'siigo', 'type' => 'SI', 'number' => $d['number'], 'name' => $name,
            'date' => $d['date'] ?? now()->toDateString(), 'due_date' => $d['due_date'] ?? $d['date'] ?? null, 'contact_id' => $d['contact_id'],
            'subtotal' => $value, 'total' => $value, 'balance' => 0, 'related_name' => $d['related_name'] ?? null,
            'observations' => 'Saldo de cartera según Siigo', 'status' => 'issued',
        ] + $extra);
    }

    private function count(bool $changed): void
    {
        $this->run->increment($changed ? 'updated' : 'skipped');
    }
}
