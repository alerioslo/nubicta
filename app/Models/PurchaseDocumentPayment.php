<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDocumentPayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'due_date' => 'date'];
    }
}
