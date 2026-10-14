<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesDocumentItemTax extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:4', 'value' => 'decimal:2'];
    }
}
