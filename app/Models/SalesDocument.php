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

    /** Estado del correo al cliente: los de Siigo (sent, read, not_sent, failed_delivery) y los del envio automatico. */
    public const MAIL_STATUSES = [
        'sent' => ['Enviado', 'ok'], 'read' => ['Leído', 'ok'], 'queued' => ['En cola', 'off'], 'not_sent' => ['Sin enviar', 'warn'], 'no_email' => ['Sin correo', 'warn'],
        'blocked' => ['Correo bloqueado', 'danger'], 'failed' => ['Fallo de envío', 'danger'], 'failed_delivery' => ['Fallo de entrega', 'danger'],
    ];

    /** @return array{text:string, badge:string} */
    public function mailBadge(): array
    {
        [$text, $badge] = self::MAIL_STATUSES[$this->mail_status] ?? ['Sin enviar', 'warn'];

        return ['text' => $text, 'badge' => $badge];
    }

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
