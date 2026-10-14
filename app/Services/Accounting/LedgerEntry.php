<?php

namespace App\Services\Accounting;

use App\Models\Contact;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Asiento en construccion: agrupa por cuenta y tercero, cuadra centavos de redondeo y escribe en ledger_lines. */
class LedgerEntry
{
    /** @var array<string, array{account_id:int, contact:?Contact, debit:float, credit:float}> */
    private array $lines = [];

    public function __construct(private readonly AccountResolver $accounts, private readonly ?Contact $contact = null) {}

    public function debit(int $accountId, float $value, ?Contact $contact = null): self
    {
        return $this->add($accountId, $value, 0, $contact);
    }

    public function credit(int $accountId, float $value, ?Contact $contact = null): self
    {
        return $this->add($accountId, 0, $value, $contact);
    }

    public function add(int $accountId, float $debit, float $credit, ?Contact $contact = null): self
    {
        if ($debit < 0 || $credit < 0) {
            [$debit, $credit] = [max($debit, 0) + max(-$credit, 0), max($credit, 0) + max(-$debit, 0)];
        }
        if (round($debit, 2) == 0 && round($credit, 2) == 0) {
            return $this;
        }
        $contact ??= $this->contact;
        $key = $accountId.'|'.($contact?->id ?? 0);
        $this->lines[$key] ??= ['account_id' => $accountId, 'contact' => $contact, 'debit' => 0.0, 'credit' => 0.0];
        $this->lines[$key]['debit'] += $debit;
        $this->lines[$key]['credit'] += $credit;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** @return list<array{account_id:int, contact:?Contact, debit:float, credit:float}> */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->lines as $l) {
            $net = round($l['debit'] - $l['credit'], 2);
            if ($net != 0) {
                $lines[] = ['account_id' => $l['account_id'], 'contact' => $l['contact'], 'debit' => max($net, 0), 'credit' => max(-$net, 0)];
            }
        }
        $diff = round(array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit')), 2);
        if (abs($diff) > 1) {
            throw new RuntimeException('El asiento contable no cuadra (diferencia de '.number_format($diff, 2, ',', '.').').');
        }
        if ($diff != 0) {
            $side = $diff > 0 ? 'credit' : 'debit';
            $candidates = array_keys(array_filter($lines, fn ($l) => $l[$side] > 0));
            usort($candidates, fn ($a, $b) => $lines[$b][$side] <=> $lines[$a][$side]);
            if (! $candidates) {
                throw new RuntimeException('El asiento contable no cuadra.');
            }
            $lines[$candidates[0]][$side] = round($lines[$candidates[0]][$side] + abs($diff), 2);
        }

        return $lines;
    }

    /** Escribe el asiento en el libro y devuelve el total debito. */
    public function post(string|\DateTimeInterface $date, string $document, string $documentType): float
    {
        $rows = [];
        foreach ($this->lines() as $l) {
            $c = $l['contact'];
            $rows[] = [
                'company_id' => CurrentCompany::id(),
                'date' => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date,
                'account_code' => $this->accounts->code($l['account_id']),
                'account_id' => $l['account_id'],
                'identification' => $c?->identification,
                'branch' => (string) (int) ($c?->branch_office ?? 0),
                'contact_name' => $c?->name,
                'contact_id' => $c?->id,
                'document' => $document,
                'document_type' => $documentType,
                'debit' => $l['debit'],
                'credit' => $l['credit'],
                'source' => 'erp',
            ];
        }
        if ($rows) {
            DB::table('ledger_lines')->insert($rows);
        }

        return round(array_sum(array_column($rows, 'debit')), 2);
    }

    public static function remove(string $document): int
    {
        return DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('document', $document)->where('source', 'erp')->delete();
    }
}
