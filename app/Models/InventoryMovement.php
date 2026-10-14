<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Linea del kardex. Se crea solo a traves de InventoryService. */
class InventoryMovement extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public const CONCEPTS = [
        'saldo_inicial' => 'Saldo inicial', 'venta' => 'Venta', 'devolucion_venta' => 'Devolución en venta',
        'compra' => 'Compra', 'devolucion_compra' => 'Devolución en compra', 'ajuste' => 'Ajuste',
        'traslado' => 'Traslado', 'ensamble' => 'Ensamble',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'quantity' => 'decimal:4', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:2',
            'balance_quantity' => 'decimal:4', 'balance_cost' => 'decimal:2', 'average_cost' => 'decimal:6',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
