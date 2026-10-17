<?php

namespace App\Services\Documents;

use App\Models\Contact;
use App\Models\DocumentType;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Services\Accounting\AccountResolver;
use Illuminate\Support\Facades\DB;

/**
 * "Cruce deuda con anticipos" (recibo de pago) y "Cruce cartera con anticipos" (recibo de caja):
 * arma un comprobante contable que abona las facturas pendientes más antiguas contra el saldo de anticipos del tercero.
 */
class AdvanceCrossing
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /** @return array{debt:float, advance:float, types:list<array{id:int, label:string}>} */
    public function summary(string $module, int $contactId): array
    {
        $contact = Contact::query()->find($contactId);

        return [
            'debt' => $contact ? round((float) $this->pending($module, $contact)->sum('balance'), 2) : 0.0,
            'advance' => $contact ? $this->advance($module, $contact)['balance'] : 0.0,
            'types' => DocumentType::query()->where('type', 'CC')->where('active', true)->orderBy('code')->get()
                ->map(fn ($t) => ['id' => $t->id, 'label' => "CC-{$t->code} - {$t->name}"])->values()->all(),
        ];
    }

    /** Líneas iniciales del comprobante contable (formato del formulario JournalEntry). */
    public function journalLines(string $module, int $contactId): array
    {
        $contact = Contact::query()->findOrFail($contactId);
        $advance = $this->advance($module, $contact);
        $left = $advance['balance'];
        if ($left <= 0) {
            return [];
        }
        $sales = $module === 'ventas';
        $counter = DB::table('accounts')->find($this->accounts->default($sales ? 'customers' : 'suppliers'));
        $advanceAccount = DB::table('accounts')->find($advance['account_id']);
        $contactLabel = "{$contact->identification} - {$contact->name}";
        $line = fn ($account, $due, string $description, float $debit, float $credit) => [
            'account_id' => $account->id, 'account_label' => "{$account->code} - {$account->name}", 'due_type' => $account->due_type, 'third' => true,
            'contact_id' => $contact->id, 'contact_label' => $contactLabel, 'due' => $due, 'description' => $description, 'debit' => $debit, 'credit' => $credit,
        ];
        $lines = [];
        $crossed = 0.0;
        foreach ($this->pending($module, $contact) as $doc) {
            if ($left <= 0) {
                break;
            }
            $value = round(min($left, (float) $doc->balance), 2);
            $left = round($left - $value, 2);
            $crossed += $value;
            $due = ['mode' => 'cruzar', 'document_id' => $doc->id, 'label' => "{$doc->name}-1"];
            $lines[] = $sales ? $line($counter, $due, "Cruce con anticipo {$doc->name}", 0, $value) : $line($counter, $due, "Cruce con anticipo {$doc->name}", $value, 0);
        }
        if (! $lines) {
            return [];
        }
        [$prefix, $number] = $advance['document'];
        $due = ['mode' => 'crear', 'prefix' => $prefix, 'number' => $number, 'quote' => 1, 'due_date' => now()->toDateString(), 'label' => "{$prefix}-{$number}-1"];
        $crossed = round($crossed, 2);
        $lines[] = $sales ? $line($advanceAccount, $due, 'Cruce de anticipos', $crossed, 0) : $line($advanceAccount, $due, 'Cruce de anticipos', 0, $crossed);

        return $lines;
    }

    private function pending(string $module, Contact $contact)
    {
        return ($module === 'ventas' ? SalesDocument::class : PurchaseDocument::class)::query()
            ->where('contact_id', $contact->id)->where('balance', '>', 0)->orderByRaw('COALESCE(due_date, date)')->orderBy('id')->get();
    }

    /** Saldo de la cuenta de anticipos del tercero en el libro y el último comprobante que la movió (para el detalle contable). */
    private function advance(string $module, Contact $contact): array
    {
        $accountId = $this->accounts->default($module === 'ventas' ? 'customer_advances' : 'supplier_advances');
        $q = DB::table('ledger_lines')->where('account_id', $accountId)->where(fn ($w) => $w->where('contact_id', $contact->id)->orWhere('identification', $contact->identification));
        $sum = (clone $q)->selectRaw('COALESCE(SUM(debit), 0) d, COALESCE(SUM(credit), 0) c')->first();
        $balance = round($module === 'ventas' ? $sum->c - $sum->d : $sum->d - $sum->c, 2);
        $last = (clone $q)->where($module === 'ventas' ? 'credit' : 'debit', '>', 0)->orderByDesc('date')->orderByDesc('id')->value('document');
        $document = $last && preg_match('/^([A-Z]+)-(?:\d+-)?(\d+)$/', $last, $m) ? [$m[1], (int) $m[2]] : [$module === 'ventas' ? 'RC' : 'RP', 1];

        return ['balance' => max(0.0, $balance), 'account_id' => $accountId, 'document' => $document];
    }
}
