<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiigoImportRun extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function errors(): HasMany
    {
        return $this->hasMany(SiigoImportError::class, 'run_id');
    }
}
