<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Jobs\SendPayrollDocumentMail;
use App\Models\Company;
use App\Models\PayrollDocument;
use App\Services\Payroll\ElectronicPayroll;
use App\Services\Payroll\PayrollMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Acciones de Nomina > Documentos electronicos: generar, enviar a la DIAN, nota de ajuste, ver detalle, XML y eliminar. */
class ElectronicPayrollController extends Controller
{
    public function generate(Request $request, ElectronicPayroll $payroll): RedirectResponse
    {
        $data = $request->validate(['periodo' => ['required', 'date_format:Y-m']], ['periodo.required' => 'Selecciona el periodo de nómina']);
        try {
            $n = $payroll->generate($data['periodo']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/nomina/documentos?periodo='.$data['periodo'])->with('success', $n ? "Se generaron $n documentos." : 'Todos los documentos del periodo ya fueron aprobados por la DIAN.');
    }

    public function send(Request $request, ElectronicPayroll $payroll): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']])['ids'];
        $ok = 0;
        $errors = [];
        foreach (PayrollDocument::query()->whereIn('id', $ids)->whereIn('type', ElectronicPayroll::TYPES)->orderBy('period')->orderBy('number')->get() as $doc) {
            try {
                $r = $payroll->send($doc);
                $r['valid'] ? $ok++ : $errors[] = "{$doc->consecutive}: {$r['message']}";
            } catch (\RuntimeException $e) {
                $errors[] = "{$doc->consecutive}: {$e->getMessage()}";
            }
        }
        $simulated = \App\Models\DianSetting::current()->simulated ? ' (simulado)' : '';

        return back()->with($errors ? 'error' : 'success', "Aprobados por la DIAN$simulated: $ok.".($errors ? ' '.implode(' ', array_slice($errors, 0, 5)) : ''));
    }

    public function adjust(Request $request, int $id, ElectronicPayroll $payroll): RedirectResponse
    {
        $type = (int) $request->validate(['tipo' => ['required', 'in:1,2']])['tipo'];
        try {
            $note = $payroll->adjust(PayrollDocument::query()->findOrFail($id), $type);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Se creó la nota de ajuste {$note->consecutive} en estado Preliminar. Selecciónala y envíala a la DIAN.");
    }

    public function show(int $id): Response
    {
        return Inertia::render('Payroll/ElectronicDocument', $this->sheet(PayrollDocument::query()->findOrFail($id)) + ['print' => request()->boolean('imprimir')]);
    }

    /** Vista de impresion sin sesion (firmada) que Chromium convierte en PDF para descargar o enviar por correo. */
    public function signedPrint(Request $request, int $id): Response
    {
        \App\Support\CurrentCompany::set((int) $request->input('c'));

        return Inertia::render('Payroll/ElectronicDocumentPdf', $this->sheet(PayrollDocument::query()->findOrFail($id)));
    }

    public function pdf(int $id, PayrollMailer $mailer)
    {
        $doc = PayrollDocument::query()->whereIn('type', ElectronicPayroll::TYPES)->findOrFail($id);
        try {
            $file = $mailer->pdf($doc);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->download($file, str_replace('-', '', $doc->consecutive).'.pdf', ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }

    public function excel(Request $request)
    {
        if ($request->input('tab') === 'correo') {
            $rows = PayrollController::mailQuery($request)->get()->map(fn (PayrollDocument $d) => [
                $d->generated_at->format('d/m/Y'), $d->period, $d->type, $d->consecutive, $d->contact?->name, $d->contact?->identification,
                $d->contract_number, PayrollMailer::emailFor($d), $d->mail_status,
            ]);

            return ArrayExport::download('envios_correo_nomina.xlsx', ['Fecha documento', 'Periodo', 'Tipo', 'Consecutivo', 'Empleado', '# Identificación', '#Contrato', 'Correo electrónico', 'Envió al empleado'], $rows, 'Envíos por correo');
        }
        $rows = PayrollDocument::query()->whereIn('type', ElectronicPayroll::TYPES)->with('contact:id,name,identification')
            ->when($request->input('periodo'), fn ($q, $p) => $q->where('period', $p))
            ->orderByDesc('generated_at')->orderByDesc('number')->get()->map(fn (PayrollDocument $d) => [
                $d->generated_at->format('d/m/Y'), $d->type, $d->consecutive, $d->contact?->name, $d->contact?->identification, $d->contract_number,
                (float) $d->net, $d->status, $d->cune,
            ]);

        return ArrayExport::download('documentos_nomina_electronica.xlsx', ['Fecha de generación', 'Tipo', 'Consecutivo', 'Empleado', '# Identificación', '#Contrato', 'Neto', 'Estado', 'CUNE'], $rows, 'Envío a la DIAN');
    }

    /** "Enviar al correo electronico": uno se envia de inmediato; varios (o todos los filtrados) se encolan. */
    public function mail(Request $request, PayrollMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['ids' => ['array'], 'ids.*' => ['integer'], 'all' => ['boolean']]);
        $docs = ($data['all'] ?? false)
            ? PayrollController::mailQuery($request)->get()
            : PayrollDocument::query()->whereIn('type', ElectronicPayroll::TYPES)->where('status', 'Aprobado')->whereIn('id', $data['ids'] ?? [])->get();
        if ($docs->isEmpty()) {
            return back()->with('error', 'Selecciona al menos un documento aprobado por la DIAN.');
        }
        if (! \App\Services\Mail\CompanyMailer::configured(\App\Support\CurrentCompany::id())) {
            return back()->with('error', 'El servidor no tiene configurado el correo de salida (SMTP).');
        }
        if ($docs->count() === 1) {
            try {
                $mailer->send($docs->first());
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }

            return back()->with('success', "Se envió {$docs->first()->consecutive} a ".PayrollMailer::emailFor($docs->first()->fresh()).'.');
        }
        $missing = $docs->filter(fn ($d) => ! filter_var(PayrollMailer::emailFor($d), FILTER_VALIDATE_EMAIL));
        foreach ($docs->diff($missing) as $d) {
            SendPayrollDocumentMail::dispatch($d->id, $d->company_id);
        }

        return back()->with('success', 'Se están enviando '.($docs->count() - $missing->count()).' documentos por correo; el estado se actualiza al terminar cada envío.'
            .($missing->isNotEmpty() ? " {$missing->count()} no tienen correo electrónico válido." : ''));
    }

    /** "Modificar correo electronico": Guardar y enviar, opcionalmente reemplazando el correo del empleado. */
    public function updateEmail(Request $request, int $id, PayrollMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:160'], 'replace' => ['boolean']], ['email.email' => 'Escribe un correo electrónico válido.']);
        $doc = PayrollDocument::query()->whereIn('type', ElectronicPayroll::TYPES)->where('status', 'Aprobado')->findOrFail($id);
        $doc->update(['email' => $data['email']]);
        if ($data['replace'] ?? false) {
            $doc->contact?->update(['email' => $data['email']]);
        }
        try {
            $mailer->send($doc, $data['email']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Se envió {$doc->consecutive} a {$data['email']}.");
    }

    /** Configuracion: provisiones mensuales y prefijos (bloqueados cuando la DIAN ya aprobo un documento con ellos). */
    public function updateConfig(Request $request): RedirectResponse
    {
        $current = ElectronicPayroll::config();
        $data = $request->validate([
            'sum_provisions' => ['boolean'],
            'prefix' => ['required', 'regex:/^[A-Z0-9]{1,4}$/'], 'start' => ['required', 'integer', 'min:1'],
            'note_prefix' => ['required', 'regex:/^[A-Z0-9]{1,4}$/', 'different:prefix'], 'note_start' => ['required', 'integer', 'min:1'],
        ], ['note_prefix.different' => 'Debes usar un prefijo diferente al del documento soporte de nómina.', 'prefix.regex' => 'El prefijo admite hasta 4 letras mayúsculas o números.', 'note_prefix.regex' => 'El prefijo admite hasta 4 letras mayúsculas o números.']);
        if (ElectronicPayroll::locked('Soporte de pago', $current)) {
            [$data['prefix'], $data['start']] = [$current['prefix'], $current['start']];
        }
        if (ElectronicPayroll::locked('Nota de ajuste', $current)) {
            [$data['note_prefix'], $data['note_start']] = [$current['note_prefix'], $current['note_start']];
        }
        if ($data['note_prefix'] === $data['prefix']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['note_prefix' => 'Debes usar un prefijo diferente al del documento soporte de nómina.']);
        }
        $company = $request->user()->company;
        $company->update(['payroll_settings' => [...($company->payroll_settings ?? []), 'electronic' => [...$current, ...$data]]]);

        return redirect('/nomina/documentos?tab=configuracion')->with('success', 'Se guardó la configuración de nómina electrónica.');
    }

    private function sheet(PayrollDocument $doc): array
    {
        $doc->loadMissing(['items', 'contact', 'employee']);
        $company = Company::query()->find($doc->company_id);
        $items = fn (string $type) => $doc->items->where('type', $type)->map(fn ($i) => ['label' => $i->label, 'quantity' => (float) $i->quantity, 'value' => (float) $i->value])->values();

        return [
            'doc' => [
                'id' => $doc->id, 'type' => $doc->type, 'consecutive' => $doc->consecutive, 'status' => $doc->status, 'date' => $doc->generated_at->format('d/m/Y'),
                'period' => $doc->period, 'employee' => $doc->contact?->name, 'identification' => $doc->contact?->identification, 'contract' => $doc->contract_number,
                'worked_days' => (float) $doc->worked_days, 'salary' => (float) $doc->salary, 'earnings' => (float) $doc->earnings, 'deductions' => (float) $doc->deductions,
                'net' => (float) $doc->net, 'cune' => $doc->cune, 'message' => $doc->dian_message, 'xml' => (bool) $doc->xml_path,
                'note' => [1 => 'Reemplazar', 2 => 'Eliminar'][$doc->note_type] ?? null,
                'predecessor' => $doc->predecessor_id ? PayrollDocument::query()->whereKey($doc->predecessor_id)->value('consecutive') : null,
                'earning_items' => $items('earning'), 'deduction_items' => $items('deduction'),
            ],
            'company' => ['name' => $company?->business_name, 'nit' => $company?->nit, 'dv' => $company?->dv, 'logo' => $company?->logo_path ? '/storage/'.$company->logo_path : null],
        ];
    }

    public function xml(int $id): StreamedResponse
    {
        $doc = PayrollDocument::query()->findOrFail($id);
        abort_unless($doc->xml_path && Storage::disk('local')->exists($doc->xml_path), 404);

        return Storage::disk('local')->download($doc->xml_path, basename($doc->xml_path));
    }

    public function destroy(int $id): RedirectResponse
    {
        $doc = PayrollDocument::query()->whereIn('type', ElectronicPayroll::TYPES)->findOrFail($id);
        if (! in_array($doc->status, ['Preliminar', 'Rechazado'], true)) {
            return back()->with('error', 'Solo se pueden eliminar documentos que no han sido aprobados por la DIAN.');
        }
        $doc->items()->delete();
        $doc->delete();

        return back()->with('success', "Se eliminó el documento {$doc->consecutive}.");
    }
}
