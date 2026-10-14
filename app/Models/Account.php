<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    /** Longitud del codigo por nivel en el PUC colombiano. */
    public const LEVEL_LENGTHS = [1 => 1, 2 => 2, 3 => 4, 4 => 6, 5 => 8];

    public const LEVEL_NAMES = [1 => 'Clase', 2 => 'Grupo', 3 => 'Cuenta', 4 => 'Subcuenta', 5 => 'Auxiliar'];

    protected function casts(): array
    {
        return [
            'fiscal_difference' => 'boolean',
            'transactional' => 'boolean',
            'requires_third_party' => 'boolean',
            'requires_cost_center' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public static function levelForCode(string $code): int
    {
        $len = strlen($code);
        foreach (self::LEVEL_LENGTHS as $level => $l) {
            if ($len <= $l) {
                return $level;
            }
        }

        return 5;
    }

    public static function natureForCode(string $code): string
    {
        return in_array($code[0], ['2', '3', '4', '9'], true) ? 'credito' : 'debito';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function label(): string
    {
        return "{$this->code} - {$this->name}";
    }
}
