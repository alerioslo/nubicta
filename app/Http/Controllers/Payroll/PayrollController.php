<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Employee;
use App\Models\PayrollDocument;
use App\Models\PayrollRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Modulo Nomina con las pestanas de Siigo: Nominas, Empleados y Documentos electronicos. */
class PayrollController extends Controller
{
    public function runs(Request $request): Response
    {
        $runs = PayrollRun::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('status', 'like', "%$t%")))
            ->orderByDesc('period_start')
            ->paginate($request->integer('per_page', 10))->withQueryString()
            ->through(fn (PayrollRun $r) => [
                'id' => $r->id,
                'status' => ['draft' => 'Borrador', 'posted' => $r->payment_document ? 'Pagada' : 'Contabilizada'][$r->status] ?? $r->status,
                'done' => in_array($r->status, ['posted', 'Terminada'], true),
                'name' => $r->name,
                'type' => $r->settlement_type,
                'period' => $r->period_start->format('d/m/Y').' - '.$r->period_end->format('d/m/Y'),
                'total' => (float) $r->total,
            ]);

        return Inertia::render('Payroll/Runs', ['runs' => $runs, 'tabs' => $this->tabs(), 'filters' => $request->only('q')]);
    }

    public function employees(Request $request): Response
    {
        $employees = Employee::query()
            ->with('contact:id,name,identification')
            ->when($request->input('q'), fn ($q, $t) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
            ->when($request->input('estado', 'activos') === 'activos', fn ($q) => $q->where('active', true))
            ->orderByDesc('salary')->orderByDesc('start_date')
            ->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(fn (Employee $e) => [
                'id' => $e->id,
                'contact_id' => $e->contact_id,
                'name' => $e->contact?->name,
                'identification' => $e->contact?->identification,
                'contract' => $e->contract_number ?? 'No aplica',
                'start' => $e->start_date?->translatedFormat('d M Y') ?? 'No aplica',
                'end' => $e->end_date?->translatedFormat('d M Y') ?? 'No aplica',
                'salary' => (float) $e->salary,
                'type' => $e->contract_type,
                'active' => $e->active,
            ]);

        return Inertia::render('Payroll/Employees', ['employees' => $employees, 'tabs' => $this->tabs(), 'filters' => $request->only('q', 'estado')]);
    }

    public function documents(Request $request): Response
    {
        $types = \App\Services\Payroll\ElectronicPayroll::TYPES;
        $periods = PayrollDocument::query()->whereIn('type', $types)->whereNotNull('period')->distinct()->pluck('period')
            ->merge(PayrollRun::query()->whereIn('status', ['posted', 'paid', 'Terminada'])->selectRaw("DATE_FORMAT(period_end, '%Y-%m') AS p")->distinct()->pluck('p'))
            ->push(now()->format('Y-m'))->unique()->sortDesc()->values();
        $period = $request->input('periodo', now()->format('Y-m'));

        $docs = PayrollDocument::query()->whereIn('type', $types)
            ->with('contact:id,name,identification')
            ->when($request->input('q'), fn ($q, $t) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
            ->when(! $request->input('q'), fn ($q) => $q->where('period', $period))
            ->orderByDesc('generated_at')->orderByDesc('number')->orderBy('consecutive')
            ->limit(500)->get();
        if ($docs->isEmpty() && ! $request->input('q')) {
            $docs = PayrollDocument::query()->whereIn('type', $types)->with('contact:id,name,identification')->orderByDesc('generated_at')->orderBy('consecutive')->limit(50)->get();
        }

        $tab = in_array($request->input('tab'), ['correo', 'configuracion'], true) ? $request->input('tab') : 'dian';
        $config = \App\Services\Payroll\ElectronicPayroll::config();

        return Inertia::render('Payroll/Documents', [
            'tab' => $tab,
            'mail' => $tab === 'correo' ? $this->mailList($request) : null,
            'config' => $tab === 'configuracion' ? [...$config,
                'locked' => \App\Services\Payroll\ElectronicPayroll::locked('Soporte de pago', $config),
                'note_locked' => \App\Services\Payroll\ElectronicPayroll::locked('Nota de ajuste', $config)] : null,
            'docs' => $docs->map(fn (PayrollDocument $d) => [
                'id' => $d->id,
                'date' => $d->generated_at->format('d/m/Y'),
                'type' => $d->type,
                'consecutive' => $d->consecutive,
                'employee' => $d->contact?->name,
                'identification' => $d->contact?->identification,
                'contract' => $d->contract_number,
                'net' => (float) $d->net,
                'status' => $d->status,
                'sendable' => in_array($d->status, ['Preliminar', 'Rechazado'], true),
                'adjustable' => $d->status === 'Aprobado' && filled($d->cune),
                'xml' => (bool) $d->xml_path,
                'message' => $d->dian_message,
            ]),
            'periods' => $periods,
            'period' => $period,
            'total' => PayrollDocument::query()->whereIn('type', $types)->count(),
            'tabs' => $this->tabs(),
            'filters' => $request->only('q'),
        ]);
    }

    /** Envios por correo: documentos aprobados por la DIAN con el estado del envio al empleado (filtros de Siigo). */
    public static function mailQuery(Request $request)
    {
        return PayrollDocument::query()->whereIn('type', \App\Services\Payroll\ElectronicPayroll::TYPES)->where('status', 'Aprobado')
            ->with('contact:id,name,identification,email')
            ->when($request->input('q'), fn ($q, $t) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
            ->when($request->input('desde'), fn ($q, $d) => $q->where('generated_at', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->where('generated_at', '<=', $d))
            ->when($request->input('tipo'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('estado'), fn ($q, $s) => $q->where('mail_status', $s))
            ->orderByDesc('generated_at')->orderByDesc('number');
    }

    private function mailList(Request $request): array
    {
        $page = self::mailQuery($request)->paginate(10)->withQueryString();

        return [
            'rows' => collect($page->items())->map(fn (PayrollDocument $d) => [
                'id' => $d->id, 'date' => $d->generated_at->format('d/m/Y'), 'period' => $d->period ? ((int) substr($d->period, 5, 2)).'/'.substr($d->period, 0, 4) : '',
                'type' => $d->type, 'consecutive' => $d->consecutive, 'employee' => $d->contact?->name, 'identification' => $d->contact?->identification,
                'contract' => $d->contract_number, 'email' => \App\Services\Payroll\PayrollMailer::emailFor($d), 'mail_status' => $d->mail_status,
                'sent_at' => $d->mail_sent_at?->format('d/m/Y h:i a'),
            ]),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            'page' => $page->currentPage(), 'last' => $page->lastPage(),
            'filters' => $request->only('q', 'desde', 'hasta', 'tipo', 'estado'),
            'smtp' => \App\Services\Mail\CompanyMailer::configured(\App\Support\CurrentCompany::id()),
        ];
    }

    private function tabs(): array
    {
        return DashboardController::tabs('nomina');
    }
}
