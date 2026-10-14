<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Grupo de inventario: define las cuentas contables de los productos. */
class AccountGroup extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function costAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cost_account_id');
    }

    public function returnAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'return_account_id');
    }
}
