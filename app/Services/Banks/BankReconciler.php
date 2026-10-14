<?php

namespace App\Services\Banks;

use App\Models\BankAccount;
use App\Models\Contact;
use App\Services\Accounting\AccountResolver;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Conciliacion bancaria: importa el extracto, cruza contra el auxiliar de la cuenta y contabiliza las partidas del banco. */
class BankReconciler
{
    public const DAYS = 3;

    public function __construct(
        private readonly BancolombiaStatementParser $parser,
        private readonly AccountResolver $accounts,
    ) {}

    /** @return array{statement_id:int, new:int, duplicated:int, format:string, matched:int} */
    public function import(BankAccount $bank, string $path, string $name, ?int $year = null): array
    {
        $parsed = $this->parser->parse($path, $name, $year);
        $lines = $parsed['lines'];

        return DB::transaction(function () use ($bank, $name, $parsed, $lines) {
            $statementId = DB::table('bank_statements')->insertGetId([
                'company_id' => $bank->company_id, 'bank_account_id' => $bank->id, 'file_name' => mb_substr($name, 0, 250), 'format' => $parsed['format'],
                'period_from' => $lines[0]['date'], 'period_to' => end($lines)['date'],
                'opening_balance' => $parsed['opening'], 'closing_balance' => $parsed['closing'], 'lines_count' => count($lines),
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $seen = [];
            $new = 0;
            foreach ($lines as $l) {
                $key = implode('|', [$l['date'], number_format($l['amount'], 2, '.', ''), mb_strtoupper((string) $l['description']), $l['reference'], $l['document'], $l['balance']]);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $new += DB::table('bank_statement_lines')->insertOrIgnore([
                    'bank_statement_id' => $statementId, 'company_id' => $bank->company_id, 'bank_account_id' => $bank->id,
                    'date' => $l['date'], 'description' => $l['description'], 'branch' => $l['branch'], 'reference' => $l['reference'],
                    'document' => $l['document'], 'amount' => $l['amount'], 'balance' => $l['balance'],
                    'fingerprint' => sha1($key.'#'.$seen[$key]), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('bank_statements')->where('id', $statementId)->update(['lines_count' => $new]);
            if (! $new) {
                DB::table('bank_statements')->where('id', $statementId)->delete();
            }
            $matched = $this->autoMatch($bank, $lines[0]['date'], end($lines)['date']);

            return ['statement_id' => $new ? $statementId : 0, 'new' => $new, 'duplicated' => count($lines) - $new, 'format' => $parsed['format'], 'matched' => $matched];
        });
    }

    /** Cruza por valor exacto y fecha (+/- 3 dias), prefiriendo la fecha mas cercana y luego el documento con el mismo numero. */
    public function autoMatch(BankAccount $bank, string $from, string $to): int
    {
        $lines = DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->whereNull('ledger_line_id')
            ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        if ($lines->isEmpty()) {
            return 0;
        }
        $taken = $this->matchedLedgerIds($bank);
        $candidates = DB::table('ledger_lines')->where('company_id', $bank->company_id)->where('account_id', $bank->account_id)
            ->whereBetween('date', [Carbon::parse($from)->subDays(self::DAYS)->toDateString(), Carbon::parse($to)->addDays(self::DAYS)->toDateString()])
            ->get(['id', 'date', 'document', 'debit', 'credit'])
            ->reject(fn ($c) => isset($taken[$c->id]))
            ->groupBy(fn ($c) => number_format((float) $c->debit - (float) $c->credit, 2, '.', ''));

        $count = 0;
        foreach ($lines as $l) {
            $key = number_format((float) $l->amount, 2, '.', '');
            $pool = $candidates->get($key);
            if (! $pool || $pool->isEmpty()) {
                continue;
            }
            $best = $pool->map(fn ($c) => [$c, abs(Carbon::parse($c->date)->diffInDays(Carbon::parse($l->date)))])
                ->filter(fn ($p) => $p[1] <= self::DAYS)
                ->sortBy(fn ($p) => $p[1] * 10 + ($l->document && str_ends_with((string) $p[0]->document, '-'.$l->document) ? 0 : 1))
                ->first();
            if (! $best) {
                continue;
            }
            DB::table('bank_statement_lines')->where('id', $l->id)->update(['ledger_line_id' => $best[0]->id, 'match_type' => 'auto', 'updated_at' => now()]);
            $candidates->put($key, $pool->reject(fn ($c) => $c->id === $best[0]->id)->values());
            $count++;
        }

        return $count;
    }

    public function match(int $lineId, int $ledgerLineId): void
    {
        $line = DB::table('bank_statement_lines')->find($lineId) ?? throw new RuntimeException('Movimiento del extracto no encontrado.');
        $bank = BankAccount::query()->findOrFail($line->bank_account_id);
        $ledger = DB::table('ledger_lines')->where('id', $ledgerLineId)->where('company_id', $bank->company_id)->where('account_id', $bank->account_id)->first()
            ?? throw new RuntimeException('El movimiento contable no es de esta cuenta bancaria.');
        if (isset($this->matchedLedgerIds($bank)[$ledger->id])) {
            throw new RuntimeException('Ese movimiento contable ya está conciliado con otra partida.');
        }
        if (abs(((float) $ledger->debit - (float) $ledger->credit) - (float) $line->amount) > 0.009) {
            throw new RuntimeException('Los valores no coinciden: extracto '.number_format((float) $line->amount, 2).' vs libros '.number_format((float) $ledger->debit - (float) $ledger->credit, 2).'.');
        }
        DB::table('bank_statement_lines')->where('id', $lineId)->update(['ledger_line_id' => $ledger->id, 'match_type' => 'manual', 'updated_at' => now()]);
    }

    public function unmatch(int $lineId): void
    {
        DB::table('bank_statement_lines')->where('id', $lineId)->update(['ledger_line_id' => null, 'match_type' => null, 'updated_at' => now()]);
    }

    /** Contabiliza una partida del extracto que no esta en libros (4x1000, cuota de manejo, intereses...) y la deja conciliada. */
    public function post(int $lineId, string $concept, ?int $accountId = null, ?int $contactId = null): string
    {
        $line = DB::table('bank_statement_lines')->find($lineId) ?? throw new RuntimeException('Movimiento del extracto no encontrado.');
        if ($line->ledger_line_id) {
            throw new RuntimeException('El movimiento ya está conciliado.');
        }
        $bank = BankAccount::query()->findOrFail($line->bank_account_id);
        $def = config("accounting.bank_concepts.$concept") ?? throw new RuntimeException('Concepto no válido.');
        $counter = $accountId ?: ($def['account'] ? $this->accounts->id($def['account']) : null);
        if (! $counter) {
            throw new RuntimeException('Selecciona la cuenta contable de contrapartida.');
        }
        $contact = $contactId ?: Contact::query()->where('identification', config('accounting.bank_contact'))->value('id');
        $amount = abs((float) $line->amount);
        $income = (float) $line->amount > 0;
        $description = mb_substr(trim(($def['label'] ?? '').' - '.($line->description ?? '')), 0, 190);
        $type = app(Numbering::class)->journalType('1', 'Comprobante contable');

        return DB::transaction(function () use ($line, $bank, $counter, $contact, $amount, $income, $description, $type) {
            $je = app(JournalRegistrar::class)->journal([
                'document_type' => $type->id, 'date' => $line->date, 'observations' => 'Conciliación bancaria '.$bank->name.': '.$line->description,
                'items' => [
                    ['account_id' => $bank->account_id, 'contact_id' => $contact, 'description' => $description, 'debit' => $income ? $amount : 0, 'credit' => $income ? 0 : $amount],
                    ['account_id' => $counter, 'contact_id' => $contact, 'description' => $description, 'debit' => $income ? 0 : $amount, 'credit' => $income ? $amount : 0],
                ],
            ]);
            $ledgerId = DB::table('ledger_lines')->where('company_id', $bank->company_id)->where('document', $je->name)->where('account_id', $bank->account_id)->value('id');
            DB::table('bank_statement_lines')->where('id', $line->id)->update(['ledger_line_id' => $ledgerId, 'match_type' => 'created', 'journal_entry_id' => $je->id, 'updated_at' => now()]);

            return $je->name;
        });
    }

    /** Sugerencia de concepto segun la descripcion del extracto. */
    public static function suggest(?string $description, float $amount): string
    {
        $d = ' '.mb_strtoupper(\Illuminate\Support\Str::ascii((string) $description)).' ';
        foreach (config('accounting.bank_concepts') as $key => $def) {
            foreach ($def['keywords'] as $k) {
                if (str_contains($d, mb_strtoupper(\Illuminate\Support\Str::ascii($k)))) {
                    if ($key === 'interest_income' && $amount < 0) {
                        continue;
                    }

                    return $key;
                }
            }
        }

        return 'other';
    }

    /** @return array<int,true> */
    private function matchedLedgerIds(BankAccount $bank): array
    {
        return DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->whereNotNull('ledger_line_id')
            ->pluck('ledger_line_id')->flip()->map(fn () => true)->all();
    }

    /** Resumen al corte: saldo en libros + partidas del banco sin registrar - registros sin reflejar en el banco = saldo del extracto. */
    public function summary(BankAccount $bank, string $until): array
    {
        $first = DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->min('date');
        $book = $bank->balance($until);
        $lastLine = DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->where('date', '<=', $until)
            ->whereNotNull('balance')->orderByDesc('date')->orderByDesc('id')->first();
        $statementBalance = $lastLine ? (float) $lastLine->balance : null;
        if ($statementBalance === null && $first) {
            $opening = DB::table('bank_statements')->where('bank_account_id', $bank->id)->whereNotNull('opening_balance')->orderBy('period_from')->value('opening_balance');
            if ($opening !== null) {
                $statementBalance = (float) $opening + (float) DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->where('date', '<=', $until)->sum('amount');
            }
        }

        $bankOnly = $first ? DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->whereNull('ledger_line_id')->where('date', '<=', $until)
            ->orderBy('date')->get() : collect();
        $taken = $this->matchedLedgerIds($bank);
        $bookOnly = $first ? DB::table('ledger_lines')->where('company_id', $bank->company_id)->where('account_id', $bank->account_id)
            ->whereBetween('date', [$first, $until])->orderBy('date')->orderBy('id')
            ->get(['id', 'date', 'document', 'document_type', 'identification', 'contact_name', 'debit', 'credit'])
            ->reject(fn ($l) => isset($taken[$l->id]))->values() : collect();

        $bankOnlySum = round((float) $bankOnly->sum('amount'), 2);
        $bookOnlySum = round((float) $bookOnly->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);
        $reconciled = round($book + $bankOnlySum - $bookOnlySum, 2);

        return [
            'from' => $first, 'until' => $until, 'book' => round($book, 2), 'statement' => $statementBalance,
            'bank_only' => $bankOnly, 'bank_only_sum' => $bankOnlySum,
            'book_only' => $bookOnly, 'book_only_sum' => $bookOnlySum,
            'reconciled' => $reconciled, 'difference' => $statementBalance === null ? null : round($statementBalance - $reconciled, 2),
        ];
    }
}
