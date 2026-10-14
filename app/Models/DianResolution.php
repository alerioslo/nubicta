<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DianResolution extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['technical_key'];

    protected function casts(): array
    {
        return ['resolution_date' => 'date', 'valid_from' => 'date', 'valid_to' => 'date', 'technical_key' => 'encrypted'];
    }
}
