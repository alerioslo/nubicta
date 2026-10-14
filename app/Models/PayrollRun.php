<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'payment_date' => 'date', 'total' => 'decimal:2', 'posted_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PayrollDocument::class);
    }

    public function novelties(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PayrollNovelty::class);
    }
}
