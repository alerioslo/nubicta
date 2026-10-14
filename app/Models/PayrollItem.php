<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'rate' => 'decimal:6', 'base' => 'decimal:2', 'value' => 'decimal:2', 'salary_base' => 'boolean'];
    }
}
