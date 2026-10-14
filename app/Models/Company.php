<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_responsibilities' => 'array',
            'tributes' => 'array',
            'payroll_settings' => 'array',
            'closed_until' => 'date',
            'active' => 'boolean',
            'show_trade_name' => 'boolean', 'sells_medicines_eps' => 'boolean', 'handles_aiu' => 'boolean', 'two_charge_taxes' => 'boolean',
            'iva_withholding_agent' => 'boolean', 'ad_valorem' => 'boolean', 'foreign_currency' => 'boolean', 'has_partners' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_code', 'code');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_code', 'code');
    }

    public function fullNit(): string
    {
        return $this->dv !== null && $this->dv !== '' ? "{$this->nit}-{$this->dv}" : $this->nit;
    }
}
