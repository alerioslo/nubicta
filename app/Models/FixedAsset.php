<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAsset extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'siigo_created_at' => 'datetime', 'siigo_modified_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FixedAssetGroup::class, 'fixed_asset_group_id');
    }
}
