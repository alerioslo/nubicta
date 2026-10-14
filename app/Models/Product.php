<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = ['Product' => 'Producto', 'Service' => 'Servicio', 'Combo' => 'Combo'];

    public const TAX_CLASSIFICATIONS = ['Taxed' => 'Gravado', 'Exempt' => 'Exento', 'Excluded' => 'Excluido'];

    protected function casts(): array
    {
        return [
            'stock_control' => 'boolean', 'visible_in_sales' => 'boolean', 'tax_included' => 'boolean', 'active' => 'boolean',
            'stock' => 'decimal:4', 'average_cost' => 'decimal:6', 'min_stock' => 'decimal:4',
        ];
    }

    public function accountGroup(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class, 'product_line_id');
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class)->withPivot('value');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function comboItems(): HasMany
    {
        return $this->hasMany(ProductComboItem::class, 'combo_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('name', 'like', "%$term%")
            ->orWhere('code', 'like', "$term%")
            ->orWhere('barcode', $term)
            ->orWhere('reference', 'like', "%$term%"));
    }

    /** Precio de la lista indicada (o la primera). */
    public function priceFor(?int $priceListId = null): float
    {
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();
        $price = $priceListId ? $prices->firstWhere('price_list_id', $priceListId) : null;

        return (float) ($price ?? $prices->sortBy('price_list_id')->first())?->value;
    }
}
