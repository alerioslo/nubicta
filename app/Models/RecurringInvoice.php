<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringInvoice extends Model
{
    use BelongsToCompany;

    /** Meses entre una factura y la siguiente. */
    public const FREQUENCIES = ['Mensual' => 1, 'Bimestral' => 2, 'Trimestral' => 3, 'Semestral' => 6, 'Anual' => 12];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'next_date' => 'date', 'active' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function finished(): bool
    {
        return $this->times !== null && $this->generated >= $this->times;
    }
}
