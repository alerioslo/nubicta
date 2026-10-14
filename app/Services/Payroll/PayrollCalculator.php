<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * Liquida un empleado en un periodo (mensual o quincenal) con sus novedades.
 * Devengos, deducciones del trabajador, aportes del empleador y provisiones de prestaciones, igual que el CC-992 de Siigo.
 */
class PayrollCalculator
{
    /** Novedades que se registran en la nomina: [etiqueta, unidad (hours|days|value), tipo]. */
    public const NOVELTIES = [
        'hed' => ['Horas extras diurnas', 'hours', 'earning'],
        'hen' => ['Horas extras nocturnas', 'hours', 'earning'],
        'rn' => ['Recargo nocturno', 'hours', 'earning'],
        'rdf' => ['Recargo dominical o festivo', 'hours', 'earning'],
        'hedf' => ['Hora extra diurna dominical o festiva', 'hours', 'earning'],
        'henf' => ['Hora extra nocturna dominical o festiva', 'hours', 'earning'],
        'rndf' => ['Recargo nocturno dominical o festivo', 'hours', 'earning'],
        'comision' => ['Comisiones', 'value', 'earning'],
        'bonificacion_salarial' => ['Bonificación salarial', 'value', 'earning'],
        'bonificacion' => ['Bonificación no salarial (ocasional)', 'value', 'earning'],
        'otro_devengo' => ['Otro devengo no salarial', 'value', 'earning'],
        'incapacidad_general' => ['Incapacidad por enfermedad general', 'days', 'earning'],
        'incapacidad_laboral' => ['Incapacidad laboral (ARL)', 'days', 'earning'],
        'licencia_maternidad' => ['Licencia de maternidad / paternidad', 'days', 'earning'],
        'licencia_remunerada' => ['Licencia remunerada / luto', 'days', 'earning'],
        'vacaciones' => ['Vacaciones disfrutadas', 'days', 'earning'],
        'vacaciones_compensadas' => ['Vacaciones compensadas en dinero', 'days', 'earning'],
        'licencia_no_remunerada' => ['Licencia no remunerada', 'days', 'absence'],
        'ausencia' => ['Ausencia injustificada', 'days', 'absence'],
        'prestamo' => ['Préstamo / anticipo a empleado', 'value', 'deduction'],
        'libranza' => ['Libranza', 'value', 'deduction'],
        'embargo' => ['Embargo judicial', 'value', 'deduction'],
        'afc' => ['Aporte voluntario AFC', 'value', 'deduction'],
        'pension_voluntaria' => ['Aporte voluntario de pensión', 'value', 'deduction'],
        'otra_deduccion' => ['Otra deducción', 'value', 'deduction'],
    ];

    private const SALARY_BASE = ['sueldo', 'salario_integral', 'hed', 'hen', 'rn', 'rdf', 'hedf', 'henf', 'rndf', 'comision', 'bonificacion_salarial',
        'vacaciones', 'incapacidad_general', 'incapacidad_laboral', 'licencia_maternidad', 'licencia_remunerada'];

    public function __construct(private readonly PayrollLaw $law) {}

    /** @param Collection<int, \App\Models\PayrollNovelty> $novelties */
    public function employee(Employee $e, PayrollRun $run, Collection $novelties): array
    {
        $start = max($run->period_start->toDateString(), $e->start_date?->toDateString() ?? '1900-01-01');
        $end = min($run->period_end->toDateString(), $e->end_date?->toDateString() ?? '2999-12-31');
        $periodDays = $run->frequency === 'Quincenal' ? 15 : 30;
        $days = min($periodDays, PayrollLaw::days360($start, $end));
        $qty = fn (string $c) => (float) $novelties->where('concept', $c)->sum('quantity');
        $val = fn (string $c) => (float) $novelties->where('concept', $c)->sum('value');

        $salary = (float) $e->salary;
        $daily = $salary / 30;
        $items = [];
        $add = function (string $concept, string $type, float $value, float $quantity = 0, float $rate = 0, float $base = 0, ?string $label = null) use (&$items) {
            $value = round($value);
            if ($value == 0) {
                return;
            }
            $items[] = ['concept' => $concept, 'label' => $label ?? (self::NOVELTIES[$concept][0] ?? self::LABELS[$concept] ?? $concept), 'type' => $type,
                'quantity' => round($quantity, 2), 'rate' => $rate, 'base' => round($base, 2), 'value' => $value, 'salary_base' => in_array($concept, self::SALARY_BASE, true)];
        };

        $absence = $qty('licencia_no_remunerada') + $qty('ausencia');
        $paidLeave = $qty('incapacidad_general') + $qty('incapacidad_laboral') + $qty('licencia_maternidad') + $qty('licencia_remunerada') + $qty('vacaciones');
        $worked = max(0, $days - $absence - $paidLeave);

        $add($e->integral_salary ? 'salario_integral' : 'sueldo', 'earning', $daily * $worked, $worked, 0, $salary, $e->integral_salary ? 'Salario integral' : 'Sueldo');

        $simple = $e->pay_mode === 'simple';
        $minimum = $this->law->minimumWage();
        if (! $simple && $e->transport_aid && ! $e->integral_salary && $salary <= 2 * $minimum) {
            $add('auxilio_transporte', 'earning', $this->law->transportAid() / 30 * $worked, $worked, 0, $this->law->transportAid(), 'Auxilio de transporte');
        }

        $hour = $salary / $this->law->hours($run->period_end->toDateString());
        foreach (array_keys(PayrollLaw::OVERTIME) as $c) {
            if ($h = $qty($c)) {
                $f = $this->law->factor($c);
                $add($c, 'earning', $hour * $f * $h, $h, $f, $hour);
            }
        }
        foreach (['comision', 'bonificacion_salarial', 'bonificacion', 'otro_devengo'] as $c) {
            $add($c, 'earning', $val($c));
        }
        if ($d = $qty('incapacidad_general')) {
            $add('incapacidad_general', 'earning', $d * max($daily * 2 / 3, $minimum / 30), $d, 0.6667, $daily);
        }
        foreach (['incapacidad_laboral', 'licencia_maternidad', 'licencia_remunerada', 'vacaciones', 'vacaciones_compensadas'] as $c) {
            if ($d = $qty($c)) {
                $add($c, 'earning', $d * $daily, $d, 1, $daily);
            }
        }

        $earnings = collect($items)->where('type', 'earning');
        $salaryPart = (float) $earnings->where('salary_base', true)->sum('value');
        $nonSalary = (float) $earnings->whereIn('concept', ['bonificacion', 'otro_devengo'])->sum('value');
        $excess = max(0, $nonSalary - 0.4 * ($salaryPart + $nonSalary));
        $ibc = ($e->integral_salary ? $salaryPart * 0.7 : $salaryPart) + $excess;
        $coveredDays = $days - $absence;
        if ($ibc > 0 && ! $simple) {
            $ibc = max($ibc, $minimum / 30 * $coveredDays);
            $ibc = min($ibc, 25 * $minimum);
        }
        $ibc = round($ibc);

        if (! $simple) {
            $add('salud', 'deduction', $ibc * (float) $e->health_rate, 0, (float) $e->health_rate, $ibc, 'Fondo de salud');
            $pension = $e->isPensioner() ? 0 : (float) $e->pension_rate;
            $add('pension', 'deduction', $ibc * $pension, 0, $pension, $ibc, 'Fondo de pensión');
            if (! $e->isPensioner() && ($fsp = $this->law->solidarity($ibc))) {
                $add('fsp', 'deduction', $ibc * $fsp, 0, $fsp, $ibc, 'Fondo de solidaridad pensional');
            }
        }
        foreach (['prestamo', 'libranza', 'embargo', 'afc', 'pension_voluntaria', 'otra_deduccion'] as $c) {
            $add($c, 'deduction', $val($c));
        }

        if (! $simple) {
            $deductions = collect($items)->where('type', 'deduction');
            $mandatory = (float) $deductions->whereIn('concept', ['salud', 'pension', 'fsp'])->sum('value');
            $voluntary = (float) $deductions->whereIn('concept', ['afc', 'pension_voluntaria'])->sum('value');
            $income = (float) $earnings->where('concept', '!=', 'auxilio_transporte')->sum('value');
            $factor = $run->frequency === 'Quincenal' ? 2 : 1;
            $net = ($income - $mandatory) * $factor;
            $exemptLimit = 0.4 * $net;
            $voluntaryExempt = min($voluntary * $factor, 0.3 * $income * $factor);
            $exempt25 = min(0.25 * max(0, $net - $voluntaryExempt), 790 / 12 * $this->law->uvt());
            $base = $net - min($voluntaryExempt + $exempt25, $exemptLimit, 1340 / 12 * $this->law->uvt());
            $add('retefuente', 'deduction', $this->law->withholding($base) / $factor, 0, 0, round($base / $factor), 'Retención en la fuente');

            $pensionRate = $e->isPensioner() ? 0 : 0.12;
            $add('pension_empresa', 'employer', $ibc * $pensionRate, 0, $pensionRate, $ibc, 'Pensión empleador');
            if (! $this->law->exempt($salary)) {
                $add('salud_empresa', 'employer', $ibc * 0.085, 0, 0.085, $ibc, 'Salud empleador');
            }
            $incapacity = (float) $earnings->whereIn('concept', ['incapacidad_general', 'incapacidad_laboral', 'licencia_maternidad'])->sum('value');
            $arlBase = max(0, $ibc - $incapacity - (float) $earnings->where('concept', 'vacaciones')->sum('value'));
            $arlRate = PayrollLaw::ARL[(int) $e->arl_class] ?? PayrollLaw::ARL[1];
            $add('arl', 'employer', $arlBase * $arlRate, 0, $arlRate, $arlBase, 'ARL');
            $ccfBase = max(0, $ibc - $incapacity);
            $add('ccf', 'employer', $ccfBase * 0.04, 0, 0.04, $ccfBase, 'Caja de compensación');
            if (! $this->law->exempt($salary)) {
                $add('sena', 'employer', $ccfBase * 0.02, 0, 0.02, $ccfBase, 'SENA');
                $add('icbf', 'employer', $ccfBase * 0.03, 0, 0.03, $ccfBase, 'ICBF');
            }

            if (! $e->integral_salary) {
                $benefitsBase = $salaryPart - $incapacity + (float) $earnings->where('concept', 'auxilio_transporte')->sum('value');
                $cesantias = round($benefitsBase * 0.0833);
                $add('prov_cesantias', 'provision', $cesantias, 0, 0.0833, $benefitsBase, 'Provisión cesantías');
                $add('prov_intereses', 'provision', $cesantias * 0.01, 0, 0.01, $cesantias, 'Provisión intereses cesantías');
                $add('prov_prima', 'provision', $benefitsBase * 0.0833, 0, 0.0833, $benefitsBase, 'Provisión prima de servicios');
            }
            $vacationBase = (float) $earnings->whereIn('concept', ['sueldo', 'salario_integral', 'vacaciones', 'licencia_remunerada'])->sum('value');
            $add('prov_vacaciones', 'provision', $vacationBase * 0.0417, 0, 0.0417, $vacationBase, 'Provisión vacaciones');
        }

        $all = collect($items);
        $earn = (float) $all->where('type', 'earning')->sum('value');
        $ded = (float) $all->where('type', 'deduction')->sum('value');

        return [
            'items' => $items, 'worked_days' => $worked, 'days' => $days, 'salary' => $salary, 'ibc' => $ibc,
            'earnings' => $earn, 'deductions' => $ded, 'net' => round($earn - $ded),
            'employer_cost' => (float) $all->where('type', 'employer')->sum('value'), 'provisions' => (float) $all->where('type', 'provision')->sum('value'),
        ];
    }

    private const LABELS = ['sueldo' => 'Sueldo', 'auxilio_transporte' => 'Auxilio de transporte'];
}
