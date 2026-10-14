<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocument extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = ['FV' => 'Factura de venta', 'NC' => 'Nota crédito', 'ND' => 'Nota débito', 'CT' => 'Cotización', 'RM' => 'Remisión', 'SI' => 'Saldo inicial'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'due_date' => 'date',
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax_total' => 'decimal:2',
            'withholding_total' => 'decimal:2', 'total' => 'decimal:2', 'balance' => 'decimal:2',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_document_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDocumentItem::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesDocumentPayment::class);
    }
}
