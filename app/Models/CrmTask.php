<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmTask extends Model
{
    use BelongsToCompany;

    /** Categorias de tarea configuradas en Siigo (Configuracion > Tarea de seguimiento comercial). */
    public const CATEGORIES = ['1 - General', '2 - Comercial', '3 - Servicios', '4 - Administración y Recursos Humanos', '5 - Sistema de Gestión de Calidad'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime', 'expected_value' => 'decimal:2'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
