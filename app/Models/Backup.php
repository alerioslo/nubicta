<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Copia de seguridad generada (kind=respaldo) o restauracion en curso (kind=restaurar). */
class Backup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'finished_at' => 'datetime'];
    }
}
