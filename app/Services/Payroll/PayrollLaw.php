<?php

namespace App\Services\Payroll;

use App\Http\Controllers\Settings\PayrollSettingsController;
use App\Models\Company;
use App\Models\PayrollConcept;
use App\Support\CurrentCompany;

/** Valores de ley y parametros de nomina de la empresa (Configuracion de nomina). */
class PayrollLaw
{
    /** Tarifa ARL por clase de riesgo (Decreto 1772 de 1994). */
    public const ARL = [1 => 0.00522, 2 => 0.01044, 3 => 0.02436, 4 => 0.0435, 5 => 0.0696];

    /** Factor sobre la hora ordinaria: horas extra (valor total) y recargos (solo el recargo). */
    public const OVERTIME = [
        'hed' => ['Horas extras diurnas 125%', 1.25], 'hen' => ['Horas extras nocturnas 175%', 1.75], 'rn' => ['Recargo nocturno', 0.35],
        'rdf' => ['Recargo dominical o festivo', 0.90], 'hedf' => ['Hora extra diurna dominical o festiva', 2.15],
        'henf' => ['Horas extras nocturnas dominical o festiva', 2.65], 'rndf' => ['Recargo nocturno dominical o festivo ordinario', 1.25],
    ];

    public readonly array $settings;

    private array $factors = [];

    public function __construct(?array $settings = null)
    {
        $this->settings = [...PayrollSettingsController::DEFAULTS, ...($settings ?? (Company::query()->find(CurrentCompany::id())?->payroll_settings ?? []))];
    }

    public function minimumWage(): float
    {
        return (float) $this->settings['minimum_wage'];
    }

    public function transportAid(): float
    {
        return (float) $this->settings['transport_aid'];
    }

    public function uvt(): float
    {
        return (float) $this->settings['uvt'];
    }

    /** Exonerada de salud empleador (8,5 %), SENA e ICBF por trabajadores que ganan menos de 10 SMMLV (art. 114-1 E.T.). */
    public function exempt(float $salary): bool
    {
        return (bool) $this->settings['exempt_health_parafiscal'] && $salary < 10 * $this->minimumWage();
    }

    /** Divisor de la hora ordinaria segun la jornada maxima legal (Ley 2101 de 2021) o la pactada si es menor. */
    public function hours(string $date): int
    {
        $law = match (true) {
            $date >= '2026-07-15' => 210,
            $date >= '2025-07-15' => 220,
            $date >= '2024-07-15' => 230,
            $date >= '2023-07-15' => 235,
            default => 240,
        };

        return min($law, (int) ($this->settings['monthly_hours'] ?? $law));
    }

    public function factor(string $key): float
    {
        if (! isset($this->factors[$key])) {
            [$description, $default] = self::OVERTIME[$key];
            $this->factors[$key] = (float) (PayrollConcept::query()->where('description', $description)->value('factor') ?: $default);
        }

        return $this->factors[$key];
    }

    /** Retencion en la fuente procedimiento 1 (art. 383 E.T.) sobre la base mensual depurada, redondeada al mil. */
    public function withholding(float $monthlyBase): float
    {
        $uvt = $this->uvt();
        if ($uvt <= 0 || $monthlyBase <= 0) {
            return 0;
        }
        $b = $monthlyBase / $uvt;
        $r = match (true) {
            $b <= 95 => 0,
            $b <= 150 => ($b - 95) * 0.19,
            $b <= 360 => ($b - 150) * 0.28 + 10,
            $b <= 640 => ($b - 360) * 0.33 + 69,
            $b <= 945 => ($b - 640) * 0.35 + 162,
            $b <= 2300 => ($b - 945) * 0.37 + 268,
            default => ($b - 2300) * 0.39 + 770,
        };

        return round($r * $uvt, -3);
    }

    /** Fondo de solidaridad pensional segun IBC en salarios minimos (Ley 797 de 2003). */
    public function solidarity(float $ibc): float
    {
        $m = $this->minimumWage() > 0 ? $ibc / $this->minimumWage() : 0;

        return match (true) {
            $m < 4 => 0,
            $m < 16 => 0.01,
            $m < 17 => 0.012,
            $m < 18 => 0.014,
            $m < 19 => 0.016,
            $m < 20 => 0.018,
            default => 0.02,
        };
    }

    /** Dias comerciales (meses de 30 dias) entre dos fechas, inclusive. */
    public static function days360(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }
        [$y1, $m1, $d1] = array_map('intval', explode('-', $from));
        [$y2, $m2, $d2] = array_map('intval', explode('-', $to));
        $d1 = min($d1, 30);
        $lastDay = (int) date('t', strtotime($to));
        $d2 = $d2 === $lastDay ? 30 : min($d2, 30);

        return ($y2 - $y1) * 360 + ($m2 - $m1) * 30 + ($d2 - $d1) + 1;
    }
}
