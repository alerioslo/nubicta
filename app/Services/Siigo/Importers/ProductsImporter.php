<?php

namespace App\Services\Siigo\Importers;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\ProductPrice;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;

/** Importa productos, impuestos, precios y saldo de inventario por bodega. */
class ProductsImporter extends BaseImporter
{
    /** @var array<string, int> */
    private array $lines = [];

    /** @var array<int, int> posicion => price_list_id */
    private array $priceLists = [];

    private int $defaultWarehouse;

    public function entity(): string
    {
        return 'productos';
    }

    protected function handle(array $params): void
    {
        ProductLine::ensureDefaults($this->companyId);
        $this->lines = ProductLine::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('id', 'code')->all();
        $this->priceLists = PriceList::withoutGlobalScopes()->where('company_id', $this->companyId)->pluck('id', 'position')->all();
        $this->defaultWarehouse = Warehouse::withoutGlobalScopes()->where('company_id', $this->companyId)
            ->orderByDesc('is_default')->orderBy('id')->value('id');

        $inventory = app(InventoryService::class);
        $this->log('Productos (v1/products)...');
        $n = 0;
        foreach ($this->siigo->paginate('v1/products') as $r) {
            $product = $this->upsert(Product::class, 'product', $r, fn ($r) => $this->map($r));
            if ($product) {
                $this->syncTaxes($product, $r['taxes'] ?? []);
                $this->syncPrices($product, $r['prices'] ?? []);
                $this->syncStock($product, $r, $inventory);
            }
            if (++$n % 500 === 0) {
                $this->log("  $n...");
            }
        }
    }

    private function map(array $r): array
    {
        $code = trim((string) $r['code']);
        $lineCode = ProductLine::codeFor($code, $this->lines);
        $extra = $r['additional_fields'] ?? [];

        return [
            'siigo_id' => $r['id'],
            'code' => $code,
            'name' => trim($r['name']),
            'type' => $r['type'] ?? 'Product',
            'account_group_id' => $this->localId('account_group', $r['account_group']['id'] ?? null),
            'product_line_id' => $lineCode ? $this->lines[$lineCode] : null,
            'unit_code' => (string) ($r['unit']['code'] ?? '94'),
            'unit_label' => trim((string) ($r['unit_label'] ?? '')) ?: ($r['unit']['name'] ?? 'unidad'),
            'reference' => ($r['reference'] ?? '') ?: null,
            'description' => ($r['description'] ?? '') ?: null,
            'barcode' => ($extra['barcode'] ?? '') ?: null,
            'brand' => ($extra['brand'] ?? '') ?: null,
            'model' => ($extra['model'] ?? '') ?: null,
            'tariff' => ($extra['tariff'] ?? '') ?: null,
            'stock_control' => (bool) ($r['stock_control'] ?? false),
            'tax_classification' => $r['tax_classification'] ?? 'Taxed',
            'tax_included' => (bool) ($r['tax_included'] ?? false),
            'active' => (bool) ($r['active'] ?? true),
        ];
    }

    private function syncTaxes(Product $product, array $taxes): void
    {
        $sync = [];
        foreach ($taxes as $t) {
            if ($id = $this->localId('tax', $t['id'] ?? null)) {
                $sync[$id] = ['value' => $t['value'] ?? null];
            }
        }
        $product->taxes()->sync($sync);
    }

    private function syncPrices(Product $product, array $prices): void
    {
        $product->prices()->delete();
        foreach ($prices as $currency) {
            foreach ($currency['price_list'] ?? [] as $p) {
                $listId = $this->priceLists[$p['position']] ?? null;
                if ($listId) {
                    ProductPrice::create([
                        'product_id' => $product->id,
                        'price_list_id' => $listId,
                        'currency' => $currency['currency_code'] ?? 'COP',
                        'value' => $p['value'] ?? 0,
                    ]);
                }
            }
        }
    }

    /** El saldo de Siigo queda como ajuste de importacion; el kardex historico se reconstruye con los documentos (fase 6). */
    private function syncStock(Product $product, array $r, InventoryService $inventory): void
    {
        if (! $product->stock_control) {
            return;
        }
        foreach ($r['warehouses'] ?? [] as $w) {
            $warehouseId = $this->localId('warehouse', $w['id'] ?? null) ?? $this->defaultWarehouse;
            $inventory->setBalance($product, $warehouseId, (float) ($w['quantity'] ?? 0), 'saldo_inicial', today(), 'SIIGO');
        }
    }
}
