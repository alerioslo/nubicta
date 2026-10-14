<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RadianEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['registered_at' => 'date', 'invoice_date' => 'date', 'finished' => 'boolean', 'receiver' => 'array', 'events' => 'array'];
    }
}
