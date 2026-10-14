<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DianSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['software_pin', 'payroll_software_pin', 'support_software_pin', 'certificate_password', 'certificate_path'];

    public const SCOPES = [
        'fe' => 'Facturación electrónica',
        'nomina' => 'Nómina electrónica',
        'ds' => 'Documento soporte',
    ];

    protected function casts(): array
    {
        return [
            'simulated' => 'boolean',
            'software_pin' => 'encrypted',
            'payroll_software_pin' => 'encrypted',
            'support_software_pin' => 'encrypted',
            'certificate_password' => 'encrypted',
            'certificate_meta' => 'array',
            'habilitation' => 'array',
            'production_since' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::firstOrCreate([], ['habilitation' => self::emptyHabilitation()]);
    }

    public static function emptyHabilitation(): array
    {
        return collect(self::SCOPES)->map(fn () => ['status' => 'pendiente', 'sent' => 0, 'accepted' => 0, 'rejected' => 0, 'approved_at' => null])->all();
    }

    public function habilitationFor(string $scope): array
    {
        return ($this->habilitation ?? [])[$scope] ?? self::emptyHabilitation()[$scope];
    }

    public function softwareFor(string $scope): array
    {
        return match ($scope) {
            'nomina' => [$this->payroll_software_id, $this->payroll_software_pin, $this->payroll_test_set_id],
            'ds' => [$this->support_software_id, $this->support_software_pin, $this->support_test_set_id],
            default => [$this->software_id, $this->software_pin, $this->test_set_id],
        };
    }

    public function hasCertificate(): bool
    {
        return filled($this->certificate_path) && filled($this->certificate_meta);
    }
}
