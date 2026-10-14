<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'opening_at' => 'datetime', 'active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Como Siigo: una caja o banco es una cuenta de categoría "Caja - Bancos" relacionada con una forma de pago activa. */
    public static function syncFromPaymentMethods(int $companyId): void
    {
        $accounts = \Illuminate\Support\Facades\DB::table('payment_methods as p')->join('accounts as a', 'a.id', '=', 'p.account_id')
            ->where('p.company_id', $companyId)->where('p.active', true)->where('a.category', 'Caja - Bancos')
            ->distinct()->get(['a.id', 'a.code', 'a.name']);
        foreach ($accounts as $a) {
            $bank = static::withoutGlobalScopes()->firstOrNew(['company_id' => $companyId, 'account_id' => $a->id]);
            $bank->fill(['name' => $a->name, 'active' => true, 'kind' => $bank->kind ?? (str_starts_with($a->code, '1105') ? 'caja' : 'banco')]);
            if ($bank->isDirty()) {
                $bank->save();
            }
        }
        static::withoutGlobalScopes()->where('company_id', $companyId)->whereNotIn('account_id', $accounts->pluck('id'))->update(['active' => false]);
    }

    /** Saldo en libros: el libro auxiliar trae la historia de Siigo mas lo contabilizado en el ERP. */
    public function balance(?string $until = null): float
    {
        return (float) \Illuminate\Support\Facades\DB::table('ledger_lines')
            ->where('company_id', $this->company_id)->where('account_id', $this->account_id)
            ->when($until, fn ($q) => $q->where('date', '<=', $until))
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')->value('net');
    }
}
