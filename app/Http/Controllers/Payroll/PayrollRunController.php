<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\PayrollDocument;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollLaw;
use App\Services\Payroll\PayrollReports;
use App\Services\Payroll\PayrollService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ciclo de una nomina (crear, novedades, contabilizar, pagar, reabrir), prestaciones, empleados y reportes de nomina. */
class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly PayrollReports $reports) {}

    public function create(Request $request): Response
    {
        $kind = $request->input('tipo', 'nomina');
        abort_unless(isset(PayrollService::KINDS[$kind]), 404);
        $today = now();
        $defaults = match ($kind) {
            'prima' => $today->month <= 6 ? [$today->year.'-01-01', $today->year.'-06-30'] : [$today->year.'-07-01', $today->year.'-12-31'],
            'cesantias' => [($today->year - ($today->month <= 2 ? 1 : 0)).'-01-01', ($today->year - ($today->month <= 2 ? 1 : 0)).'-12-31'],
            default => [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()],
        };

        return Inertia::render('Payroll/RunCreate', [
            'tabs' => $this->tabs(),
            'kind' => $kind,
            'kinds' => PayrollService::KINDS,
            'defaults' => ['period_start' => $defaults[0], 'period_end' => $defaults[1]],
            'employees' => Employee::query()->with('contact:id,name,identification')->where('active', true)->where('pay_mode', '!=', 'simple')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->contact?->name, 'identification' => $e->contact?->identification,
                    'start' => $e->start_date?->toDateString(), 'salary' => (float) $e->salary])->sortBy('name')->values(),
            'active' => Employee::query()->where('active', true)->where('pay_mode', '!=', 'simple')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(PayrollService::KINDS))],
            'frequency' => ['nullable', Rule::in(['Mensual', 'Quincenal'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'payment_date' => ['nullable', 'date'],
            'employee_id' => ['required_if:kind,liquidacion', 'nullable', 'integer', 'exists:employees,id'],
            'reason' => ['nullable', 'string', 'max:120'],
            'indemnizacion' => ['nullable', 'numeric', 'min:0'],
        ], ['employee_id.required_if' => 'Selecciona el empleado a liquidar.']);
        if ($data['kind'] === 'liquidacion') {
            $e = Employee::query()->findOrFail($data['employee_id']);
            $data['period_start'] = $e->start_date?->toDateString() ?? $data['period_start'];
        }

        return $this->attempt(function () use ($data) {
            $run = $this->payroll->create($data);

            return redirect("/nomina/{$run->id}")->with('success', "Se creó «{$run->name}». Registra las novedades y contabilízala.");
        });
    }

    public function show(PayrollRun $run): Response
    {
        $run->load(['documents.employee.contact:id,name,identification', 'documents.items', 'novelties']);
        $docs = $run->documents->sortBy(fn ($d) => $d->employee?->contact?->name ?? $d->contact?->name)->values();

        return Inertia::render('Payroll/Run', [
            'tabs' => $this->tabs(),
            'run' => [
                'id' => $run->id, 'name' => $run->name, 'kind' => $run->kind, 'type' => $run->settlement_type, 'status' => $run->status, 'source' => $run->source,
                'period' => $run->period_start->format('d/m/Y').' - '.$run->period_end->format('d/m/Y'),
                'payment_date' => ($run->payment_date ?? $run->period_end)->toDateString(),
                'total' => (float) $run->total, 'earnings' => (float) $run->earnings, 'deductions' => (float) $run->deductions,
                'employer_cost' => (float) $run->employer_cost, 'provisions' => (float) $run->provisions, 'payment_document' => $run->payment_document,
                'editable' => $run->source === 'erp' && $run->status === 'draft',
            ],
            'docs' => $docs->map(fn (PayrollDocument $d) => [
                'id' => $d->id, 'employee_id' => $d->employee_id, 'name' => $d->employee?->contact?->name ?? $d->contact?->name,
                'identification' => $d->employee?->contact?->identification, 'salary' => (float) $d->salary, 'days' => (float) $d->worked_days,
                'earnings' => (float) $d->earnings, 'deductions' => (float) $d->deductions, 'employer_cost' => (float) $d->employer_cost,
                'provisions' => (float) $d->provisions, 'net' => (float) $d->net, 'ledger' => $d->ledger_document, 'status' => $d->status,
                'items' => $d->items->map(fn ($i) => ['concept' => $i->concept, 'label' => $i->label, 'type' => $i->type, 'quantity' => (float) $i->quantity,
                    'value' => (float) $i->value])->values(),
                'novelties' => $run->novelties->where('employee_id', $d->employee_id)->map(fn ($n) => ['id' => $n->id, 'concept' => $n->concept,
                    'label' => PayrollCalculator::NOVELTIES[$n->concept][0] ?? ($n->concept === 'indemnizacion' ? 'Indemnización' : $n->concept),
                    'quantity' => (float) $n->quantity, 'value' => (float) $n->value, 'notes' => $n->notes])->values(),
            ]),
            'novelties' => collect(PayrollCalculator::NOVELTIES)->map(fn ($n, $k) => ['key' => $k, 'label' => $n[0], 'unit' => $n[1], 'type' => $n[2]])->values(),
            'banks' => BankAccount::query()->where('active', true)->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function novelty(Request $request, PayrollRun $run): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('payroll_documents', 'employee_id')->where('payroll_run_id', $run->id)],
            'concept' => ['required', Rule::in(array_keys(PayrollCalculator::NOVELTIES))],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:190'],
        ]);

        return $this->attempt(fn () => $this->payroll->addNovelty($run, $data), 'Novedad registrada y nómina recalculada.');
    }

    public function removeNovelty(PayrollRun $run, int $novelty): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->removeNovelty($run, $novelty), 'Novedad eliminada.');
    }

    public function recalculate(PayrollRun $run): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->calculate($run), 'Nómina recalculada con los datos actuales de los empleados.');
    }

    public function post(PayrollRun $run): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->post($run), 'Nómina contabilizada: se generó un comprobante por empleado.');
    }

    public function pay(Request $request, PayrollRun $run): RedirectResponse
    {
        $data = $request->validate(['bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'date' => ['required', 'date']]);

        return $this->attempt(function () use ($run, $data) {
            $name = $this->payroll->pay($run, (int) $data['bank_account_id'], $data['date']);

            return back()->with('success', "Pago registrado en $name.");
        });
    }

    public function reopen(PayrollRun $run): RedirectResponse
    {
        return $this->attempt(fn () => $this->payroll->reopen($run), 'La nómina volvió a borrador: se reversaron sus comprobantes contables.');
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        return $this->attempt(function () use ($run) {
            $this->payroll->destroy($run);

            return redirect('/nomina')->with('success', 'Nómina eliminada.');
        });
    }

    public function payslip(PayrollRun $run, PayrollDocument $doc): Response
    {
        abort_unless((int) $doc->payroll_run_id === (int) $run->id, 404);
        $doc->load('items', 'employee.contact');
        $company = Company::query()->find(CurrentCompany::id());

        return Inertia::render('Payroll/Payslip', [
            'company' => $this->companyInfo($company),
            'run' => ['name' => $run->name, 'type' => $run->settlement_type, 'period' => $run->period_start->format('d/m/Y').' - '.$run->period_end->format('d/m/Y'),
                'payment_date' => ($run->payment_date ?? $run->period_end)->format('d/m/Y')],
            'employee' => ['name' => $doc->employee?->contact?->name, 'identification' => $doc->employee?->contact?->identification, 'position' => $doc->employee?->position,
                'salary' => (float) $doc->salary, 'days' => (float) $doc->worked_days, 'contract' => $doc->contract_number, 'bank' => $doc->employee?->payment_method],
            'items' => $doc->items->whereIn('type', ['earning', 'deduction'])->map(fn ($i) => ['label' => $i->label, 'type' => $i->type, 'quantity' => (float) $i->quantity, 'value' => (float) $i->value])->values(),
            'totals' => ['earnings' => (float) $doc->earnings, 'deductions' => (float) $doc->deductions, 'net' => (float) $doc->net],
            'ledger' => $doc->ledger_document,
        ]);
    }

    public function editEmployee(Employee $employee): Response
    {
        $employee->load('contact:id,name,identification');
        $contacts = DB::table('contacts')->where('company_id', CurrentCompany::id())
            ->whereIn('id', array_filter([$employee->eps_contact_id, $employee->pension_contact_id, $employee->severance_contact_id, $employee->arl_contact_id, $employee->ccf_contact_id]))
            ->get(['id', 'name', 'identification'])->keyBy('id');

        return Inertia::render('Payroll/EmployeeEdit', [
            'tabs' => $this->tabs(),
            'employee' => [...$employee->only(['id', 'contract_number', 'contract_type', 'payment_frequency', 'position', 'eps', 'pension_fund', 'severance_fund',
                'arl', 'ccf', 'arl_class', 'bank_account', 'active', 'pay_mode', 'payroll_group', 'contributor_type', 'contributor_subtype', 'integral_salary',
                'transport_aid', 'eps_contact_id', 'pension_contact_id', 'severance_contact_id', 'arl_contact_id', 'ccf_contact_id', 'cost_center_id',
                'payment_method', 'bank_name', 'bank_account_type', 'vacation_days_opening', 'termination_reason']),
                'name' => $employee->contact?->name, 'identification' => $employee->contact?->identification,
                'start_date' => $employee->start_date?->toDateString(), 'end_date' => $employee->end_date?->toDateString(),
                'salary' => (float) $employee->salary, 'health_rate' => (float) $employee->health_rate * 100, 'pension_rate' => (float) $employee->pension_rate * 100,
                'vacation_days_opening' => (float) $employee->vacation_days_opening],
            'funds' => $contacts->map(fn ($c) => ['id' => $c->id, 'label' => "$c->identification - $c->name"])->values(),
            'vacations' => $this->payroll->vacationBalance($employee),
            'groups' => array_keys(Employee::GROUPS),
            'contractTypes' => Employee::CONTRACT_TYPES,
            'costCenters' => class_exists(CostCenter::class) ? CostCenter::query()->orderBy('code')->get(['id', 'code', 'name']) : [],
            'minimumWage' => (new PayrollLaw)->minimumWage(),
        ]);
    }

    /** Agrega como empleado a un tercero que ya existe (por ejemplo un cliente) y abre su contrato para completarlo. */
    public function storeEmployee(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', CurrentCompany::id())]],
            ['contact_id.required' => 'Selecciona el tercero que quieres agregar como empleado.']
        );
        $contact = \App\Models\Contact::query()->findOrFail($data['contact_id']);
        if ($existing = Employee::query()->where('contact_id', $contact->id)->where('active', true)->first()) {
            return redirect("/nomina/empleados/{$existing->id}/editar")->with('error', "{$contact->name} ya es empleado; aquí puedes revisar su contrato.");
        }
        $employee = DB::transaction(fn () => Employee::enroll($contact));
        $employee->active || $employee->update(['active' => true, 'end_date' => null, 'termination_reason' => null]);

        return redirect("/nomina/empleados/{$employee->id}/editar")->with('success', "{$contact->name} quedó como empleado. Completa su contrato y su información de nómina.");
    }

    public function updateEmployee(Request $request, Employee $employee): RedirectResponse
    {
        $contact = ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', CurrentCompany::id())];
        $data = $request->validate([
            'contract_number' => ['nullable', 'string', 'max:40'],
            'contract_type' => ['required', Rule::in(Employee::CONTRACT_TYPES)],
            'payment_frequency' => ['required', Rule::in(['Mensual', 'Quincenal'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary' => ['required', 'numeric', 'min:0'],
            'position' => ['nullable', 'string', 'max:120'],
            'pay_mode' => ['required', Rule::in(['nomina', 'simple'])],
            'payroll_group' => ['required', Rule::in(array_keys(Employee::GROUPS))],
            'contributor_type' => ['nullable', 'string', 'max:80'],
            'contributor_subtype' => ['nullable', 'string', 'max:80'],
            'integral_salary' => ['boolean'],
            'transport_aid' => ['boolean'],
            'health_rate' => ['required', 'numeric', 'between:0,12.5'],
            'pension_rate' => ['required', 'numeric', 'between:0,16'],
            'eps' => ['nullable', 'string', 'max:120'], 'pension_fund' => ['nullable', 'string', 'max:120'], 'severance_fund' => ['nullable', 'string', 'max:120'],
            'arl' => ['nullable', 'string', 'max:120'], 'ccf' => ['nullable', 'string', 'max:120'], 'arl_class' => ['required', 'integer', 'between:1,5'],
            'eps_contact_id' => $contact, 'pension_contact_id' => $contact, 'severance_contact_id' => $contact, 'arl_contact_id' => $contact, 'ccf_contact_id' => $contact,
            'cost_center_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string', 'max:60'], 'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_type' => ['nullable', 'string', 'max:30'], 'bank_account' => ['nullable', 'string', 'max:60'],
            'vacation_days_opening' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
            'termination_reason' => ['nullable', 'string', 'max:120'],
        ]);
        $data['health_rate'] = $data['health_rate'] / 100;
        $data['pension_rate'] = $data['pension_rate'] / 100;
        $data['vacation_days_opening'] = (float) ($data['vacation_days_opening'] ?? 0);
        $employee->update($data);

        return redirect('/nomina/empleados')->with('success', 'Empleado actualizado.');
    }

    public function pila(Request $request): Response
    {
        $month = $request->input('mes', now()->subMonth()->format('Y-m'));

        return Inertia::render('Payroll/Pila', ['tabs' => $this->tabs(), 'report' => $this->reports->pila($month)]);
    }

    public function pilaExport(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $report = $this->reports->pila($request->input('mes', now()->subMonth()->format('Y-m')));
        $columns = [['Identificación', 'text'], ['Empleado', 'text'], ['Días', 'int'], ['IBC', 'money'], ['EPS', 'text'], ['Salud', 'money'], ['AFP', 'text'], ['Pensión', 'money'],
            ['FSP', 'money'], ['ARL', 'text'], ['Clase', 'text'], ['ARL valor', 'money'], ['CCF', 'text'], ['CCF valor', 'money'], ['SENA', 'money'], ['ICBF', 'money'], ['Novedades', 'text']];
        $rows = array_map(fn ($r) => [$r['identification'], $r['name'], $r['days'], $r['ibc'], $r['eps'], $r['health'], $r['afp'], $r['pension'], $r['fsp'], $r['arl'],
            $r['arl_class'], $r['arl_value'], $r['ccf'], $r['ccf_value'], $r['sena'], $r['icbf'], $r['novelties']], $report['rows']);
        $sum = fn ($i) => array_sum(array_column($rows, $i));
        $totals = [null, null, null, $sum(3), null, $sum(5), null, $sum(7), $sum(8), null, null, $sum(11), null, $sum(13), $sum(14), $sum(15), null];
        $title = 'Aportes de seguridad social y parafiscales';
        $filters = ['Mes' => $report['month']];
        $file = "aportes-{$report['month']}";

        if ($request->input('formato') === 'pdf') {
            return \App\Services\Reports\TablePdf::download("$file.pdf", $title, $columns, $rows, $totals, $filters);
        }

        return \App\Exports\ArrayExport::download("$file.xlsx", array_column($columns, 0), $rows, 'Aportes', array_column($columns, 1), $title, $filters, $totals);
    }

    public function vacations(): Response
    {
        return Inertia::render('Payroll/Vacations', ['tabs' => $this->tabs(), 'rows' => $this->reports->vacations()]);
    }

    public function certificate(Request $request): Response
    {
        $year = $request->integer('anio', now()->year - 1);
        $employees = Employee::query()->with('contact:id,name,identification')->where('pay_mode', '!=', 'simple')->get()->sortBy('contact.name');
        $employee = $employees->firstWhere('id', $request->integer('empleado'));
        $company = Company::query()->find(CurrentCompany::id());
        $settings = [...\App\Http\Controllers\Settings\PayrollSettingsController::DEFAULTS, ...($company?->payroll_settings ?? [])];

        return Inertia::render('Payroll/Certificate', [
            'tabs' => $this->tabs(),
            'year' => $year,
            'employees' => $employees->map(fn ($e) => ['id' => $e->id, 'name' => $e->contact?->name, 'identification' => $e->contact?->identification])->values(),
            'employee' => $employee?->id,
            'data' => $employee ? $this->reports->certificate($employee, $year) : null,
            'company' => $this->companyInfo($company) + ['signer' => $settings['certificate_signer'], 'signer_position' => $settings['certificate_signer_position'],
                'text' => $settings['certificate_text']],
        ]);
    }

    private function companyInfo(?Company $c): array
    {
        return [
            'name' => $c ? ($c->business_name ?: trim("{$c->first_name} {$c->last_name}")) : '',
            'nit' => $c ? $c->nit.($c->dv !== null ? "-{$c->dv}" : '') : '',
            'address' => $c?->address, 'phone' => $c?->phone,
            'city' => $c ? DB::table('municipalities')->where('code', $c->municipality_code)->value('name') : null,
        ];
    }

    private function attempt(callable $fn, string $ok = ''): RedirectResponse
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $r instanceof RedirectResponse ? $r : back()->with('success', $ok);
    }

    private function tabs(): array
    {
        return DashboardController::tabs('nomina');
    }
}
