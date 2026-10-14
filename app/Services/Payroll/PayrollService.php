<?php

namespace App\Services\Payroll;

use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\PayrollDocument;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerEntry;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\Numbering;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nominas del ERP: liquidacion, contabilizacion (un CC por empleado como en Siigo), pago y prestaciones
 * (prima CC-995, cesantias e intereses CC-991, liquidacion de contrato CC-994); el pago va en CC-8 "Nomina pagos" como en Siigo.
 */
class PayrollService
{
    public const KINDS = ['nomina' => 'Nómina', 'prima' => 'Prima de servicios', 'cesantias' => 'Cesantías e intereses anuales', 'liquidacion' => 'Liquidación de contrato'];

    private const JOURNAL = ['nomina' => ['992', 'Comprobante de nómina'], 'prima' => ['995', 'Comprobante liquidación de primas'],
        'cesantias' => ['991', 'Comprobante cesantías e intereses'], 'liquidacion' => ['994', 'Comprobante liquidación de contrato']];

    /** Cuenta del gasto: sufijo despues del prefijo del grupo (51 administracion, 52 ventas, 72 produccion). */
    private const EXPENSE = [
        'sueldo' => '050601', 'salario_integral' => '050301', 'auxilio_transporte' => '052701', 'hed' => '051501', 'hen' => '051501', 'rn' => '051501',
        'rdf' => '051501', 'hedf' => '051501', 'henf' => '051501', 'rndf' => '051501', 'comision' => '050601', 'bonificacion_salarial' => '054801',
        'bonificacion' => '054801', 'otro_devengo' => '054801', 'incapacidad_general' => '052401', 'incapacidad_laboral' => '052401',
        'licencia_maternidad' => '052401', 'licencia_remunerada' => '050601',
        'pension_empresa' => '057001', 'salud_empresa' => '056901', 'arl' => '056801', 'ccf' => '057201', 'sena' => '057801', 'icbf' => '057501',
        'prov_cesantias' => '053001', 'prov_intereses' => '053301', 'prov_prima' => '053601', 'prov_vacaciones' => '053901', 'indemnizacion' => '056001',
    ];

    /** Pasivos y cuentas por cobrar (codigos completos). */
    private const LIABILITY = [
        'salud' => '23700501', 'salud_empresa' => '23700501', 'pension' => '23803001', 'fsp' => '23803001', 'pension_empresa' => '23803001',
        'arl' => '23700601', 'ccf' => '23701001', 'sena' => '23701002', 'icbf' => '23701003', 'retefuente' => '23650501',
        'prestamo' => '13301501', 'libranza' => '23703001', 'embargo' => '23702501', 'afc' => '23705001', 'pension_voluntaria' => '23704501',
        'otra_deduccion' => '23809501', 'prov_cesantias' => '2510100101', 'prov_intereses' => '2510100201', 'prov_prima' => '2510100401',
        'prov_vacaciones' => '2510100301', 'vacaciones' => '2510100301', 'vacaciones_compensadas' => '2510100301', 'neto' => '25050501',
        'incapacidad_eps' => '13809501',
    ];

    public function __construct(private readonly AccountResolver $accounts, private readonly Numbering $numbering) {}

    public function create(array $data): PayrollRun
    {
        $kind = $data['kind'] ?? 'nomina';
        $start = Carbon::parse($data['period_start'])->toDateString();
        $end = Carbon::parse($data['period_end'])->toDateString();
        $this->numbering->assertOpen($end);
        $frequency = $data['frequency'] ?? 'Mensual';
        $name = $data['name'] ?? match ($kind) {
            'nomina' => 'Nómina '.($frequency === 'Quincenal' ? (Carbon::parse($start)->day <= 15 ? '1a quincena ' : '2a quincena ') : '').Carbon::parse($start)->translatedFormat('F Y'),
            'prima' => 'Prima de servicios '.(Carbon::parse($end)->month <= 6 ? 'primer' : 'segundo').' semestre '.Carbon::parse($end)->year,
            'cesantias' => 'Cesantías e intereses '.Carbon::parse($end)->year,
            'liquidacion' => 'Liquidación de contrato',
        };

        $run = PayrollRun::create([
            'company_id' => CurrentCompany::id(), 'name' => mb_substr($name, 0, 190), 'settlement_type' => self::KINDS[$kind], 'kind' => $kind,
            'frequency' => $frequency, 'period_start' => $start, 'period_end' => $end, 'payment_date' => $data['payment_date'] ?? $end,
            'total' => 0, 'status' => 'draft', 'source' => 'erp', 'created_by' => Auth::id(),
        ]);
        $employees = $kind === 'liquidacion'
            ? Employee::query()->whereKey($data['employee_id'] ?? 0)->get()
            : Employee::query()->where('active', true)->where('pay_mode', '!=', 'simple')
                ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $end))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $start))->get();
        if ($kind === 'nomina') {
            $employees = $employees->merge(Employee::query()->where('active', true)->where('pay_mode', 'simple')->get());
        }
        if ($employees->isEmpty()) {
            throw new RuntimeException('No hay empleados activos con contrato vigente en el periodo.');
        }
        foreach ($employees as $e) {
            PayrollDocument::create([
                'company_id' => $run->company_id, 'employee_id' => $e->id, 'contact_id' => $e->contact_id, 'payroll_run_id' => $run->id,
                'generated_at' => $run->payment_date, 'type' => 'Nómina individual', 'consecutive' => "BOR-{$run->id}-{$e->id}", 'contract_number' => $e->contract_number, 'net' => 0,
                'status' => 'draft', 'source' => 'erp',
            ]);
        }
        if ($kind === 'liquidacion') {
            $e = $employees->first();
            $e->forceFill(['termination_reason' => $data['reason'] ?? null])->save();
            if (! empty($data['indemnizacion'])) {
                $run->novelties()->create(['company_id' => $run->company_id, 'employee_id' => $e->id, 'concept' => 'indemnizacion', 'value' => (float) $data['indemnizacion']]);
            }
        }
        $this->calculate($run);

        return $run;
    }

    public function calculate(PayrollRun $run): void
    {
        $this->assertDraft($run);
        $calc = new PayrollCalculator(new PayrollLaw);
        DB::transaction(function () use ($run, $calc) {
            $totals = ['net' => 0, 'earnings' => 0, 'deductions' => 0, 'employer_cost' => 0, 'provisions' => 0];
            foreach ($run->documents()->with('employee')->get() as $doc) {
                $novelties = $run->novelties()->where('employee_id', $doc->employee_id)->get();
                $r = match ($run->kind) {
                    'nomina' => $calc->employee($doc->employee, $run, $novelties),
                    default => $this->benefits($run, $doc->employee, $novelties),
                };
                $doc->items()->delete();
                foreach ($r['items'] as $i) {
                    $doc->items()->create($i + ['company_id' => $run->company_id]);
                }
                $doc->forceFill([
                    'worked_days' => $r['worked_days'], 'salary' => $r['salary'], 'ibc' => $r['ibc'], 'earnings' => $r['earnings'],
                    'deductions' => $r['deductions'], 'employer_cost' => $r['employer_cost'], 'provisions' => $r['provisions'], 'net' => $r['net'],
                ])->save();
                foreach ($totals as $k => $v) {
                    $totals[$k] = $v + $r[$k];
                }
            }
            $run->forceFill(['total' => $totals['net']] + collect($totals)->except('net')->all())->save();
        });
    }

    /** Prima (semestre), cesantias e intereses (anio) o liquidacion de contrato: base promedio por dias / 360. */
    private function benefits(PayrollRun $run, Employee $e, $novelties): array
    {
        $law = new PayrollLaw;
        $from = max($run->period_start->toDateString(), $e->start_date?->toDateString() ?? '1900-01-01');
        $to = min($run->period_end->toDateString(), $e->end_date?->toDateString() ?? $run->period_end->toDateString());
        $unpaid = (float) DB::table('payroll_novelties as n')->join('payroll_runs as r', 'r.id', '=', 'n.payroll_run_id')
            ->where('n.employee_id', $e->id)->whereIn('n.concept', ['licencia_no_remunerada', 'ausencia'])->where('r.status', '!=', 'annulled')
            ->whereBetween('r.period_end', [$from, $to])->sum('n.quantity');
        $days = max(0, PayrollLaw::days360($from, $to) - $unpaid);
        $aid = $e->transport_aid && ! $e->integral_salary && (float) $e->salary <= 2 * $law->minimumWage() ? $law->transportAid() : 0;
        $base = $this->averageBase($e, $from, $to, (float) $e->salary + $aid);
        $items = [];
        $add = function (string $concept, string $label, float $value, float $qty, float $b) use (&$items) {
            if (round($value) != 0) {
                $items[] = ['concept' => $concept, 'label' => $label, 'type' => 'earning', 'quantity' => $qty, 'rate' => 0, 'base' => round($b, 2), 'value' => round($value), 'salary_base' => false];
            }
        };

        if (in_array($run->kind, ['prima', 'liquidacion'], true)) {
            $primaFrom = $run->kind === 'prima' ? $from : max($from, substr($to, 0, 4).(substr($to, 5, 2) <= '06' ? '-01-01' : '-07-01'));
            $d = $run->kind === 'prima' ? $days : PayrollLaw::days360($primaFrom, $to);
            $add('prima', 'Prima de servicios', $base * $d / 360, $d, $base);
        }
        if (in_array($run->kind, ['cesantias', 'liquidacion'], true)) {
            $cesFrom = $run->kind === 'cesantias' ? $from : max($from, substr($to, 0, 4).'-01-01');
            $d = $run->kind === 'cesantias' ? $days : PayrollLaw::days360($cesFrom, $to);
            $ces = round($base * $d / 360);
            $add('cesantias', 'Cesantías', $ces, $d, $base);
            $add('intereses', 'Intereses sobre cesantías', $ces * $d * 0.12 / 360, $d, $ces);
        }
        if ($run->kind === 'liquidacion') {
            $pending = $this->vacationBalance($e, $to)['pending'];
            $add('vacaciones_liquidacion', 'Vacaciones pendientes', (float) $e->salary / 30 * max(0, $pending), round(max(0, $pending), 2), (float) $e->salary / 30);
            $add('indemnizacion', 'Indemnización', (float) $novelties->where('concept', 'indemnizacion')->sum('value'), 0, 0);
        }
        $earn = (float) collect($items)->sum('value');
        if ($run->kind !== 'cesantias' && ($w = $law->withholding(0.75 * collect($items)->whereIn('concept', ['prima', 'indemnizacion'])->sum('value')))) {
            $items[] = ['concept' => 'retefuente', 'label' => 'Retención en la fuente', 'type' => 'deduction', 'quantity' => 0, 'rate' => 0, 'base' => 0, 'value' => $w, 'salary_base' => false];
        }
        $ded = (float) collect($items)->where('type', 'deduction')->sum('value');

        return ['items' => $items, 'worked_days' => $days, 'salary' => (float) $e->salary, 'ibc' => 0, 'earnings' => $earn, 'deductions' => $ded,
            'net' => round($earn - $ded), 'employer_cost' => 0, 'provisions' => 0];
    }

    /**
     * Base de prima y cesantias: salario mensual + auxilio de transporte vigentes al final del periodo, como se pagaban en Siigo.
     * Para periodos pasados se toman del ultimo comprobante de nomina (CC-992) del empleado en el periodo.
     */
    private function averageBase(Employee $e, string $from, string $to, float $current): float
    {
        if (substr($to, 0, 4) >= now()->format('Y')) {
            return $current;
        }
        $doc = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('identification', $e->contact?->identification)
            ->whereBetween('date', [$from, $to])->where('document', 'like', 'CC-992-%')->whereRaw("account_code REGEXP '^(51|52|72)0506'")
            ->orderByDesc('date')->value('document');
        if (! $doc) {
            return $current;
        }
        $base = (float) DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('document', $doc)
            ->whereRaw("account_code REGEXP '^(51|52|72)05(03|06|27)'")->sum('debit');

        return $base > 0 ? $base : $current;
    }

    /** Libro de vacaciones: 15 dias habiles por cada 360 dias trabajados. */
    public function vacationBalance(Employee $e, ?string $until = null): array
    {
        $until ??= now()->toDateString();
        $to = min($until, $e->end_date?->toDateString() ?? $until);
        $worked = $e->start_date ? PayrollLaw::days360($e->start_date->toDateString(), $to) : 0;
        $unpaid = (float) DB::table('payroll_novelties as n')->join('payroll_runs as r', 'r.id', '=', 'n.payroll_run_id')
            ->where('n.employee_id', $e->id)->whereIn('n.concept', ['licencia_no_remunerada'])->where('r.status', '!=', 'annulled')->sum('n.quantity');
        $earned = max(0, $worked - $unpaid) / 360 * 15;
        $taken = (float) $e->vacation_days_opening + (float) DB::table('payroll_items as i')->join('payroll_documents as d', 'd.id', '=', 'i.payroll_document_id')
            ->join('payroll_runs as r', 'r.id', '=', 'd.payroll_run_id')->where('d.employee_id', $e->id)->where('r.status', 'posted')
            ->whereIn('i.concept', ['vacaciones', 'vacaciones_compensadas', 'vacaciones_liquidacion'])->sum('i.quantity');

        return ['worked_days' => $worked, 'earned' => round($earned, 2), 'taken' => round($taken, 2), 'pending' => round($earned - $taken, 2)];
    }

    /** Contabiliza cada empleado en su comprobante (CC-992, 995, 998 o 994). */
    public function post(PayrollRun $run): void
    {
        $this->assertDraft($run);
        $date = ($run->payment_date ?? $run->period_end)->toDateString();
        $this->numbering->assertOpen($date);
        [$code, $label] = self::JOURNAL[$run->kind];
        $type = $this->numbering->journalType($code, $label);

        DB::transaction(function () use ($run, $date, $type) {
            foreach ($run->documents()->with(['employee', 'items'])->get() as $doc) {
                if ($doc->items->isEmpty()) {
                    continue;
                }
                $lines = $run->kind === 'nomina' ? $this->payrollLines($doc) : $this->benefitLines($run, $doc);
                $je = app(JournalRegistrar::class)->store($type, ['date' => $date, 'observations' => "{$run->name} - ".($doc->employee->contact?->name ?? '')], $lines, 'payroll', $doc);
                $doc->forceFill(['ledger_document' => $je->name, 'consecutive' => $je->name, 'status' => 'posted'])->save();
            }
            $run->forceFill(['status' => 'posted', 'posted_at' => now()])->save();
            if ($run->kind === 'liquidacion') {
                $doc = $run->documents()->first();
                $doc?->employee?->forceFill(['active' => false, 'end_date' => $run->period_end])->save();
            }
        });
    }

    private function payrollLines(PayrollDocument $doc): array
    {
        $e = $doc->employee;
        $employee = $e->contact;
        $party = fn (?int $id) => $id ? Contact::query()->find($id) : $employee;
        $g = $e->expensePrefix();
        $lines = [];
        $line = function (string $code, ?Contact $c, string $desc, float $debit, float $credit) use (&$lines) {
            if (round($debit, 2) == 0 && round($credit, 2) == 0) {
                return;
            }
            $lines[] = ['account_id' => $this->account($code), 'contact' => $c, 'description' => mb_substr($desc, 0, 190), 'debit' => round($debit, 2), 'credit' => round($credit, 2)];
        };
        $expense = fn (string $concept) => $g.self::EXPENSE[$concept];

        foreach ($doc->items as $i) {
            $v = (float) $i->value;
            match ($i->type) {
                'earning' => match ($i->concept) {
                    'vacaciones', 'vacaciones_compensadas' => $line(self::LIABILITY['vacaciones'], $employee, $i->label, $v, 0),
                    'incapacidad_general' => $this->incapacity($line, $expense('incapacidad_general'), $e, $employee, $i),
                    default => $line($expense($i->concept), $employee, $i->label, $v, 0),
                },
                'deduction' => $line(self::LIABILITY[$i->concept] ?? self::LIABILITY['otra_deduccion'], match ($i->concept) {
                    'salud' => $party($e->eps_contact_id), 'pension', 'fsp' => $party($e->pension_contact_id), default => $employee,
                }, $i->label, 0, $v),
                'employer' => (function () use ($line, $expense, $i, $v, $e, $party) {
                    $c = match ($i->concept) {
                        'pension_empresa' => $party($e->pension_contact_id), 'salud_empresa' => $party($e->eps_contact_id),
                        'arl' => $party($e->arl_contact_id), 'ccf', 'sena', 'icbf' => $party($e->ccf_contact_id), default => null,
                    };
                    $line($expense($i->concept), $c, $i->label, $v, 0);
                    $line(self::LIABILITY[$i->concept], $c, $i->label, 0, $v);
                })(),
                'provision' => (function () use ($line, $expense, $i, $v, $e, $employee, $party) {
                    $fund = in_array($i->concept, ['prov_cesantias', 'prov_intereses'], true) ? $party($e->severance_contact_id) : $employee;
                    $line($expense($i->concept), $fund, $i->label, $v, 0);
                    $line(self::LIABILITY[$i->concept], $employee, $i->label, 0, $v);
                })(),
                default => null,
            };
        }
        $line(self::LIABILITY['neto'], $employee, 'Neto a pagar', 0, (float) $doc->net);

        return $lines;
    }

    /** Los dos primeros dias los paga el empleador; desde el tercero se cobran a la EPS. */
    private function incapacity(callable $line, string $expense, Employee $e, Contact $employee, $item): void
    {
        $days = (float) $item->quantity;
        $own = $days > 0 ? round((float) $item->value * min(2, $days) / $days) : (float) $item->value;
        $line($expense, $employee, $item->label.' (empleador)', $own, 0);
        $eps = $e->eps_contact_id ? Contact::query()->find($e->eps_contact_id) : $employee;
        $line(self::LIABILITY['incapacidad_eps'], $eps, $item->label.' (cobro EPS)', (float) $item->value - $own, 0);
    }

    /** Prestaciones: se cancela la provision consolidada del empleado y el exceso va al gasto. */
    private function benefitLines(PayrollRun $run, PayrollDocument $doc): array
    {
        $e = $doc->employee;
        $employee = $e->contact;
        $g = $e->expensePrefix();
        $map = ['prima' => ['2510100401', '053601'], 'cesantias' => ['2510100101', '053001'], 'intereses' => ['2510100201', '053301'], 'vacaciones_liquidacion' => ['2510100301', '053901']];
        $lines = [];
        foreach ($doc->items as $i) {
            $v = (float) $i->value;
            if ($i->type === 'deduction') {
                $lines[] = ['account_id' => $this->account(self::LIABILITY['retefuente']), 'contact' => $employee, 'description' => $i->label, 'debit' => 0, 'credit' => $v];

                continue;
            }
            if ($i->concept === 'indemnizacion') {
                $lines[] = ['account_id' => $this->account($g.self::EXPENSE['indemnizacion']), 'contact' => $employee, 'description' => $i->label, 'debit' => $v, 'credit' => 0];

                continue;
            }
            [$liability, $suffix] = $map[$i->concept];
            $balance = $this->providedBalance($liability, $employee);
            $fromProvision = $run->kind === 'liquidacion' ? $balance : min($v, max(0, $balance));
            $fromProvision = max(0, $fromProvision);
            $lines[] = ['account_id' => $this->account($liability), 'contact' => $employee, 'description' => $i->label, 'debit' => round($fromProvision, 2), 'credit' => 0];
            $diff = round($v - $fromProvision, 2);
            if ($diff != 0) {
                $lines[] = ['account_id' => $this->account($g.$suffix), 'contact' => $employee, 'description' => $i->label.($diff > 0 ? ' (mayor valor)' : ' (ajuste provisión)'),
                    'debit' => max(0, $diff), 'credit' => max(0, -$diff)];
            }
        }
        $fund = $run->kind === 'cesantias' && $e->severance_contact_id ? Contact::query()->find($e->severance_contact_id) : null;
        if ($fund) {
            $ces = (float) $doc->items->firstWhere('concept', 'cesantias')?->value;
            $lines[] = ['account_id' => $this->account('23803001'), 'contact' => $fund, 'description' => 'Cesantías a consignar al fondo', 'debit' => 0, 'credit' => $ces];
            $lines[] = ['account_id' => $this->account(self::LIABILITY['neto']), 'contact' => $employee, 'description' => 'Intereses a pagar', 'debit' => 0, 'credit' => round((float) $doc->net - $ces, 2)];
        } else {
            $lines[] = ['account_id' => $this->account(self::LIABILITY['neto']), 'contact' => $employee, 'description' => 'Neto a pagar', 'debit' => 0, 'credit' => (float) $doc->net];
        }

        return array_values(array_filter($lines, fn ($l) => round($l['debit'], 2) != 0 || round($l['credit'], 2) != 0));
    }

    /** Saldo credito de una cuenta de prestaciones para el empleado (historia de Siigo + ERP). */
    private function providedBalance(string $code, Contact $employee): float
    {
        return (float) DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('account_code', $code)
            ->where('identification', $employee->identification)->selectRaw('COALESCE(SUM(credit - debit), 0) v')->value('v');
    }

    /** Comprobante de pago (CC-8): debita salarios por pagar de cada empleado contra la caja o banco. */
    public function pay(PayrollRun $run, int $bankAccountId, string $date): string
    {
        if ($run->status !== 'posted') {
            throw new RuntimeException('Primero contabiliza la nómina.');
        }
        if ($run->payment_document) {
            throw new RuntimeException("La nómina ya se pagó con {$run->payment_document}.");
        }
        $bank = BankAccount::query()->findOrFail($bankAccountId);
        $items = [];
        $total = 0;
        foreach ($run->documents()->with('employee.contact')->get() as $doc) {
            $net = (float) $doc->net;
            if ($run->kind === 'cesantias' && $doc->employee->severance_contact_id) {
                $net -= (float) $doc->items()->where('concept', 'cesantias')->sum('value');
            }
            if ($net <= 0) {
                continue;
            }
            $items[] = ['account_id' => $this->account(self::LIABILITY['neto']), 'contact' => $doc->employee->contact, 'description' => "Pago {$run->name}", 'debit' => round($net, 2), 'credit' => 0];
            $items[] = ['account_id' => $bank->account_id, 'contact' => $doc->employee->contact, 'description' => "Pago {$run->name}", 'debit' => 0, 'credit' => round($net, 2)];
            $total += $net;
        }
        if (! $items) {
            throw new RuntimeException('No hay valores por pagar.');
        }
        $type = $this->numbering->journalType('8', 'Nómina pagos');
        $je = app(JournalRegistrar::class)->store($type, ['date' => $date, 'observations' => "Pago {$run->name}"], $items, 'payroll_payment', $run);
        $run->forceFill(['payment_document' => $je->name, 'paid_at' => now()])->save();

        return $je->name;
    }

    /** Reversa contabilizacion y pago; la nomina vuelve a borrador para corregirla. */
    public function reopen(PayrollRun $run): void
    {
        if ($run->source !== 'erp') {
            throw new RuntimeException('Las nóminas importadas de Siigo son de solo consulta.');
        }
        $this->numbering->assertOpen(($run->payment_date ?? $run->period_end)->toDateString());
        DB::transaction(function () use ($run) {
            if ($run->payment_document) {
                LedgerEntry::remove($run->payment_document);
                DB::table('journal_entries')->where('company_id', $run->company_id)->where('name', $run->payment_document)->update(['status' => 'annulled', 'annulled_at' => now(), 'annulled_by' => Auth::id()]);
            }
            foreach ($run->documents as $doc) {
                if ($doc->ledger_document) {
                    LedgerEntry::remove($doc->ledger_document);
                    DB::table('journal_entries')->where('company_id', $run->company_id)->where('name', $doc->ledger_document)->update(['status' => 'annulled', 'annulled_at' => now(), 'annulled_by' => Auth::id()]);
                }
                $doc->forceFill(['ledger_document' => null, 'consecutive' => "BOR-{$run->id}-{$doc->employee_id}", 'status' => 'draft'])->save();
            }
            if ($run->kind === 'liquidacion' && ($doc = $run->documents()->first())) {
                $doc->employee?->forceFill(['active' => true])->save();
            }
            $run->forceFill(['status' => 'draft', 'posted_at' => null, 'paid_at' => null, 'payment_document' => null])->save();
        });
    }

    public function addNovelty(PayrollRun $run, array $data): void
    {
        $this->assertDraft($run);
        $def = PayrollCalculator::NOVELTIES[$data['concept']] ?? throw new RuntimeException('Concepto no válido.');
        $isValue = $def[1] === 'value';
        $amount = (float) ($isValue ? ($data['value'] ?? 0) : ($data['quantity'] ?? 0));
        if ($amount <= 0) {
            throw new RuntimeException($isValue ? 'Ingresa el valor de la novedad.' : 'Ingresa la cantidad ('.($def[1] === 'hours' ? 'horas' : 'días').').');
        }
        $run->novelties()->create([
            'company_id' => $run->company_id, 'employee_id' => (int) $data['employee_id'], 'concept' => $data['concept'],
            'quantity' => $isValue ? 0 : $amount, 'value' => $isValue ? $amount : 0, 'notes' => $data['notes'] ?? null,
            'date_from' => $data['date_from'] ?? null, 'date_to' => $data['date_to'] ?? null,
        ]);
        $this->calculate($run);
    }

    public function removeNovelty(PayrollRun $run, int $id): void
    {
        $this->assertDraft($run);
        $run->novelties()->whereKey($id)->delete();
        $this->calculate($run);
    }

    public function destroy(PayrollRun $run): void
    {
        $this->assertDraft($run);
        DB::transaction(function () use ($run) {
            foreach ($run->documents as $doc) {
                $doc->items()->delete();
                $doc->delete();
            }
            $run->novelties()->delete();
            $run->delete();
        });
    }

    private function assertDraft(PayrollRun $run): void
    {
        if ($run->source !== 'erp') {
            throw new RuntimeException('Las nóminas importadas de Siigo son de solo consulta.');
        }
        if ($run->status !== 'draft') {
            throw new RuntimeException('La nómina ya está contabilizada. Reábrela para modificarla.');
        }
    }

    private function account(string $code): int
    {
        try {
            return $this->accounts->id($code);
        } catch (RuntimeException $e) {
            if (strlen($code) === 8 && str_starts_with($code, '5') || str_starts_with($code, '72')) {
                return $this->accounts->id(substr($code, 0, 2).'050601');
            }
            throw $e;
        }
    }
}
