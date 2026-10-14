<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DianTransmission extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['response_body'];

    public const OPERATIONS = [
        'SendTestSetAsync' => 'Envío set de pruebas',
        'SendBillSync' => 'Envío de documento',
        'SendNominaSync' => 'Envío nómina',
        'GetStatusZip' => 'Consulta de lote',
        'GetStatus' => 'Consulta de documento',
        'GetNumberingRange' => 'Consulta de rangos de numeración',
    ];

    protected function casts(): array
    {
        return [
            'simulated' => 'boolean',
            'is_valid' => 'boolean',
            'errors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
