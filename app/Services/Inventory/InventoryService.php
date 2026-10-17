<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Unico punto de escritura del kardex. Costo promedio ponderado por producto (todas las bodegas),
 * las entradas recalculan el promedio, las salidas salen al promedio vigente.
 */
class InventoryService
{
    public function move(
        Product $product,
        int $warehouseId,
        float $quantity,
        string $concept,
        string|\DateTimeInterface $date,
        ?float $unitCost = null,
        ?Model $source = null,
        ?string $document = null,
    ): ?InventoryMovement {
        if (! $product->stock_control || $quantity == 0) {
            return null;
        }

        return DB::transaction(function () use ($product, $warehouseId, $quantity, $concept, $date, $unitCost, $source, $document) {
            $product = Product::withoutGlobalScopes()->lockForUpdate()->find($product->id);
            $prevQty = (float) $product->stock;
            $prevAvg = (float) $product->average_cost;

            if ($quantity > 0 && $unitCost !== null) {
                $newQty = $prevQty + $quantity;
                $avg = $newQty > 0 ? (($prevQty * $prevAvg) + ($quantity * $unitCost)) / $newQty : $unitCost;
            } else {
                $avg = $prevAvg;
                $unitCost = $prevAvg;
            }
            $balanceQty = $prevQty + $quantity;

            $movement = InventoryMovement::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'date' => $date,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'document' => $document,
                'concept' => $concept,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => round($quantity * $unitCost, 2),
                'balance_quantity' => $balanceQty,
                'balance_cost' => round($balanceQty * $avg, 2),
                'average_cost' => $avg,
            ]);

            $stock = WarehouseStock::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId]);
            $stock->increment('quantity', $quantity);
            $product->forceFill(['stock' => $balanceQty, 'average_cost' => $avg])->saveQuietly();

            return $movement;
        });
    }

    /** Deja el saldo de una bodega en la cantidad indicada (ajuste), registrando la diferencia. */
    public function setBalance(Product $product, int $warehouseId, float $quantity, string $concept, string|\DateTimeInterface $date, ?string $document = null): ?InventoryMovement
    {
        $current = (float) WarehouseStock::where(['product_id' => $product->id, 'warehouse_id' => $warehouseId])->value('quantity');

        return $this->move($product, $warehouseId, $quantity - $current, $concept, $date, null, null, $document);
    }
}
