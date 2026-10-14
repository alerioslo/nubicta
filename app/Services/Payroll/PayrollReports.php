<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** PILA (resumen de aportes), libro de vacaciones y certificado de ingresos y retenciones (formulario 220). */
class PayrollReports
{
    public function __construct(private readonly PayrollService $payroll) {}

    /** Aportes del mes por empleado a partir de las nominas contabilizadas del ERP. */
    public function pila(string $month): array
    {
        $from = "$month-01";
        $to = date('Y-m-t', strtotime($from));
        $docs = DB::table('payroll_documents as d')->join('payroll_runs as r', 'r.id', '=', 'd.payroll_run_id')
            ->join('employees as e', 'e.id', '=', 'd.employee_id')->join('contacts as c', 'c.id', '=', 'e.contact_id')
            ->where('r.company_id', CurrentCompany::id())->where('r.kind', 'nomina')->where('r.status', 'posted')->where('e.pay_mode', '!=', 'simple')
            ->whereBetween('r.period_end', [$from, $to])
            ->get(['d.id', 'd.worked_days', 'd.ibc', 'e.id as employee_id', 'c.identification', 'c.name', 'e.eps', 'e.pension_fund', 'e.arl', 'e.ccf', 'e.arl_class',
                'e.eps_contact_id', 'e.pension_contact_id', 'e.arl_contact_id', 'e.ccf_contact_id']);
        $items = DB::table('payroll_items')->whereIn('payroll_document_id', $docs->pluck('id'))->get()->groupBy('payroll_document_id');
        $entity = fn ($id, $fallback) => $id ? (DB::table('contacts')->where('id', $id)->value('name') ?? $fallback) : $fallback;

        $rows = $docs->groupBy('employee_id')->map(function ($group) use ($items, $entity) {
            $first = $group->first();
            $sum = fn (array $concepts) => round((float) $group->sum(fn ($d) => ($items[$d->id] ?? collect())->whereIn('concept', $concepts)->sum('value')));
            $novel = fn (array $concepts) => (float) $group->sum(fn ($d) => ($items[$d->id] ?? collect())->whereIn('concept', $concepts)->sum('quantity'));

            return [
                'identification' => $first->identification, 'name' => $first->name,
                'days' => min(30, (float) $group->sum('worked_days') + $novel(['vacaciones', 'incapacidad_general', 'incapacidad_laboral', 'licencia_maternidad', 'licencia_remunerada'])),
                'ibc' => round((float) $group->sum('ibc')),
                'eps' => $entity($first->eps_contact_id, $first->eps), 'health' => $sum(['salud', 'salud_empresa']),
                'afp' => $entity($first->pension_contact_id, $first->pension_fund), 'pension' => $sum(['pension', 'pension_empresa']), 'fsp' => $sum(['fsp']),
                'arl' => $entity($first->arl_contact_id, $first->arl), 'arl_class' => (int) $first->arl_class, 'arl_value' => $sum(['arl']),
                'ccf' => $entity($first->ccf_contact_id, $first->ccf), 'ccf_value' => $sum(['ccf']), 'sena' => $sum(['sena']), 'icbf' => $sum(['icbf']),
                'novelties' => collect(['vacaciones' => 'VAC', 'incapacidad_general' => 'IGE', 'incapacidad_laboral' => 'IRL', 'licencia_maternidad' => 'LMA',
                    'licencia_no_remunerada' => 'SLN', 'licencia_remunerada' => 'VAC'])->filter(fn ($code, $c) => $novel([$c]) > 0)->values()->unique()->implode(' '),
            ];
        })->values();

        $totals = collect(['health', 'pension', 'fsp', 'arl_value', 'ccf_value', 'sena', 'icbf'])->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all();

        return ['month' => $month, 'rows' => $rows, 'totals' => $totals + ['total' => array_sum($totals)]];
    }

    public function vacations(): array
    {
        return Employee::query()->with('contact:id,name,identification')->where('pay_mode', '!=', 'simple')
            ->where(fn ($q) => $q->where('active', true)->orWhereNotNull('end_date'))
            ->orderByDesc('active')->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id, 'name' => $e->contact?->name, 'identification' => $e->contact?->identification, 'active' => $e->active,
                'start' => $e->start_date?->toDateString(), 'end' => $e->end_date?->toDateString(), 'salary' => (float) $e->salary,
                'opening' => (float) $e->vacation_days_opening,
                ...$this->payroll->vacationBalance($e),
                'value' => round((float) $e->salary / 30 * max(0, $this->payroll->vacationBalance($e)['pending'])),
            ])->filter(fn ($r) => $r['start'])->values()->all();
    }

    /**
     * Certificado de ingresos y retenciones por rentas de trabajo (formulario 220) desde el libro auxiliar:
     * cada comprobante de nomina es de un empleado, asi se atribuyen tambien los aportes que tienen como tercero al fondo.
     */
    public function certificate(Employee $e, int $year): array
    {
        $id = $e->contact?->identification;
        $from = "$year-01-01";
        $to = "$year-12-31";
        $docs = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereBetween('date', [$from, $to])
            ->where('identification', $id)->where(fn ($q) => $q->where('document', 'like', 'CC-99%')->orWhere('document', 'like', 'CC-8-%'))
            ->distinct()->pluck('document');
        $lines = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereIn('document', $docs)->whereBetween('date', [$from, $to])
            ->get(['document', 'account_code', 'identification', 'debit', 'credit']);
        $mine = $lines->where('identification', $id);
        $debit = fn ($rx, $set = null) => round((float) ($set ?? $mine)->filter(fn ($l) => preg_match($rx, $l->account_code))->sum(fn ($l) => (float) $l->debit - (float) $l->credit));
        $credit = fn ($rx, $set = null) => round((float) ($set ?? $lines)->filter(fn ($l) => preg_match($rx, $l->account_code))->sum(fn ($l) => (float) $l->credit - (float) $l->debit));

        $salaries = $debit('/^(51|52|72)05(03|06|12|15|24)/');
        $other = $debit('/^(51|52|72)05(27|45|48)/');
        $paid = fn ($rx) => round((float) $mine->filter(fn ($l) => preg_match($rx, $l->account_code))->sum('debit'));
        $benefits = $paid('/^2510100(3|4)/') + $debit('/^(51|52|72)0560/');
        $severance = $paid('/^2510100(1|2)/');
        $health = $credit('/^237005/') - $debit('/^(51|52|72)0569/', $lines);
        $pension = $credit('/^238030/') - $debit('/^(51|52|72)0570/', $lines);
        $withholding = $credit('/^236505/');
        $gross = $salaries + $other + $benefits + $severance;

        return [
            'year' => $year, 'employee' => ['name' => $e->contact?->name, 'identification' => $id, 'position' => $e->position],
            'salaries' => $salaries, 'other' => $other, 'benefits' => $benefits, 'severance' => $severance, 'gross' => $gross,
            'health' => max(0, round($health)), 'pension' => max(0, round($pension)), 'withholding' => $withholding,
            'documents' => $docs->count(),
        ];
    }
}
