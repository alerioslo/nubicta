<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const CONTRACT_TYPES = ['Indefinido', 'Fijo', 'Obra o labor', 'Aprendizaje', 'Prácticas'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'salary' => 'decimal:2', 'active' => 'boolean', 'integral_salary' => 'boolean',
            'transport_aid' => 'boolean', 'health_rate' => 'decimal:4', 'pension_rate' => 'decimal:4', 'vacation_days_opening' => 'decimal:2'];
    }

    public const GROUPS = ['Administración' => '51', 'Ventas' => '52', 'Producción' => '72'];

    public function expensePrefix(): string
    {
        return self::GROUPS[$this->payroll_group] ?? '51';
    }

    public function isPensioner(): bool
    {
        return str_contains(mb_strtolower((string) $this->contributor_type.' '.$this->contributor_subtype), 'pensionado');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
