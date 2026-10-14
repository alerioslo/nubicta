<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
