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

    /** Vuelve empleado a un tercero (cliente, proveedor u otro): lo marca y le abre un contrato con salario mínimo para completarlo. */
    public static function enroll(Contact $contact): self
    {
        if (! $contact->is_employee) {
            $contact->forceFill(['is_employee' => true])->save();
        }

        return self::query()->where('contact_id', $contact->id)->orderByDesc('active')->orderByDesc('id')->first()
            ?? self::query()->create([
                'contact_id' => $contact->id, 'contract_type' => 'Indefinido', 'payment_frequency' => 'Mensual',
                'start_date' => now()->toDateString(), 'salary' => (new \App\Services\Payroll\PayrollLaw)->minimumWage(), 'active' => true,
            ]);
    }
}
