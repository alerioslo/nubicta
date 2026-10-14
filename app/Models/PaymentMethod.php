<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const MEANS = [
        '10' => 'Efectivo', '42' => 'Consignación bancaria', '47' => 'Transferencia débito bancaria',
        '48' => 'Tarjeta crédito', '49' => 'Tarjeta débito', '20' => 'Cheque', 'ZZZ' => 'Otro',
    ];

    public const TYPES = [
        'CarteraProveedor' => 'Caja / Banco',
        'Cartera' => 'Crédito (cuentas por cobrar / pagar)',
        'Anticipo' => 'Anticipo',
        'Otro' => 'Otro',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'boolean', 'active' => 'boolean', 'document_types' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
