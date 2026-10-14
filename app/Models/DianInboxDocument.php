<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Factura electronica recibida de un proveedor (Bandeja DIAN / Causacion automatica). */
class DianInboxDocument extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'total' => 'decimal:2'];
    }
}
