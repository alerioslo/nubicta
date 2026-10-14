<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RC recibo de caja (recaudo de clientes) o RP recibo de pago / egreso (pago a proveedores). */
class Receipt extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = ['RC' => 'Recibo de caja', 'RP' => 'Recibo de pago'];

    protected function casts(): array
    {
        return ['date' => 'date', 'total' => 'decimal:2'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }
}
