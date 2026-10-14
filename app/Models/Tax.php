<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tax extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = [
        'IVA' => '01', 'Impoconsumo' => '04', 'ReteIVA' => '05', 'Retefuente' => '06', 'ReteICA' => '07', 'Autorretencion' => '06',
    ];

    public const WITHHOLDINGS = ['Retefuente', 'ReteIVA', 'ReteICA', 'Autorretencion'];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'base_minimum' => 'decimal:2',
            'fixed_value' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Tax $tax) {
            $tax->dian_code = self::TYPES[$tax->type] ?? $tax->dian_code;
            $tax->base_minimum ??= 0;
        });
    }

    /** Fracción a aplicar sobre la base: en Siigo la tarifa de ReteICA es por mil (6 = 6/1000), las demás son porcentaje. */
    public static function rate(?string $type, mixed $percentage): float
    {
        return (float) $percentage / ($type === 'ReteICA' ? 1000 : 100);
    }

    /** Tarifa en porcentaje, como la pide el UBL de la DIAN (ReteICA 6/1000 = 0.6 %). */
    public static function percent(?string $type, mixed $percentage): float
    {
        return self::rate($type, $percentage) * 100;
    }

    public function isWithholding(): bool
    {
        return in_array($this->type, self::WITHHOLDINGS, true);
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    public function purchasesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'purchases_account_id');
    }
}
