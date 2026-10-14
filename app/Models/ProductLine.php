<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Linea de producto del deposito (mascotas, bovinos, ...). */
class ProductLine extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Codigo de linea a partir del codigo del producto, igual que el modulo de deposito:
     * se quitan 4 digitos finales y, si no existe esa linea, 5.
     *
     * @param  array<string, mixed>  $lines  codigos de linea validos (llaves)
     */
    public static function codeFor(string $productCode, array $lines): ?string
    {
        if (! ctype_digit($productCode)) {
            return null;
        }
        foreach ([4, 5] as $digits) {
            if (strlen($productCode) > $digits) {
                $prefix = substr($productCode, 0, -$digits);
                if (array_key_exists($prefix, $lines)) {
                    return $prefix;
                }
            }
        }

        return null;
    }

    /**
     * Siguiente codigo, igual que getLastPGet del deposito: el codigo es linea + 1 digito de sublinea + consecutivo;
     * se toma el mayor consecutivo de la linea y se suma 1 (relleno a 3 digitos).
     *
     * @return array{code: string, last: ?string, sequence: string}
     */
    public function nextCode(): array
    {
        $skip = strlen($this->code) + 1;
        $last = Product::where('product_line_id', $this->id)->pluck('code')
            ->filter(fn ($c) => ctype_digit($c) && strlen($c) > $skip)
            ->sortBy(fn ($c) => (int) substr($c, $skip))
            ->last();

        if ($last === null) {
            return ['code' => $this->code.'1'.'001', 'last' => null, 'sequence' => '001'];
        }
        $sequence = str_pad((string) ((int) substr($last, $skip) + 1), 3, '0', STR_PAD_LEFT);

        return ['code' => substr($last, 0, $skip).$sequence, 'last' => $last, 'sequence' => $sequence];
    }

    public static function ensureDefaults(int $companyId): void
    {
        foreach (config('erp.product_lines') as $code => $name) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'code' => (string) $code],
                ['name' => $name],
            );
        }
    }
}
