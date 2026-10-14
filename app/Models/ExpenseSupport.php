<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Soporte de compra o gasto (foto o PDF) pendiente de causar en una factura de compra. */
class ExpenseSupport extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn (self $s) => $s->purchase_document_id ? null : Storage::disk('local')->delete($s->path));
    }
}
