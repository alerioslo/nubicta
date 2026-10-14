<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentType extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = [
        'FV' => 'Factura de venta',
        'NC' => 'Nota crédito',
        'ND' => 'Nota débito',
        'RC' => 'Recibo de caja',
        'CT' => 'Cotización',
        'RM' => 'Remisión',
        'FC' => 'Factura de compra / gasto',
        'DS' => 'Documento soporte',
        'NA' => 'Nota de ajuste documento soporte',
        'CE' => 'Recibo de pago / egreso',
        'OC' => 'Orden de compra',
        'CC' => 'Comprobante contable',
        'NI' => 'Nómina electrónica',
    ];

    public const ELECTRONIC_TYPES = [
        'NoElectronic' => 'No electrónico',
        'ElectronicInvoice' => 'Factura electrónica',
        'ElectronicCreditNote' => 'Nota crédito electrónica',
        'ElectronicDebitNote' => 'Nota débito electrónica',
        'SupportDocument' => 'Documento soporte electrónico',
        'SupportDocumentAdjustment' => 'Nota de ajuste documento soporte',
        'ElectronicPayroll' => 'Nómina electrónica',
    ];

    protected function casts(): array
    {
        return [
            'automatic_number' => 'boolean',
            'cost_center' => 'boolean',
            'cost_center_mandatory' => 'boolean',
            'seller_by_item' => 'boolean',
            'decimals' => 'boolean',
            'reteiva' => 'boolean',
            'reteica' => 'boolean',
            'self_withholding' => 'boolean',
            'resolution_date' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'technical_key' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public function isElectronic(): bool
    {
        return $this->electronic_type !== 'NoElectronic';
    }

    /** Reserva el siguiente consecutivo de forma atomica. */
    public function takeNumber(): int
    {
        return DB::transaction(function () {
            $row = self::query()->whereKey($this->id)->lockForUpdate()->first();
            $number = (int) $row->consecutive;
            if ($row->range_to && $number > $row->range_to) {
                throw new \RuntimeException("El consecutivo $number supera el rango autorizado ({$row->range_to}) para {$row->name}.");
            }
            $row->increment('consecutive');
            $this->consecutive = $number + 1;

            return $number;
        });
    }

    /** Nombre como lo muestra Siigo: FV-2 */
    public function label(): string
    {
        return "{$this->type}-{$this->code}";
    }
}
