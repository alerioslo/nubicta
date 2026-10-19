<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\DianResolution;
use App\Models\DianSetting;
use App\Models\DianTransmission;
use App\Models\DocumentType;
use App\Services\Dian\CertificateService;
use App\Services\Dian\DianService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ElectronicInvoicingController extends Controller
{
    public function edit(Request $request): Response
    {
        $dian = DianService::forCurrent();
        $s = $dian->settings();
        $this->ensureSupportTypes();

        return Inertia::render('Settings/ElectronicInvoicing', [
            'tab' => $request->query('tab', 'habilitacion'),
            'settings' => [
                'environment' => $s->environment,
                'simulated' => $s->simulated,
                'software_id' => $s->software_id,
                'test_set_id' => $s->test_set_id,
                'payroll_software_id' => $s->payroll_software_id,
                'payroll_test_set_id' => $s->payroll_test_set_id,
                'support_software_id' => $s->support_software_id,
                'support_test_set_id' => $s->support_test_set_id,
                'has_pin' => filled($s->software_pin),
                'has_payroll_pin' => filled($s->payroll_software_pin),
                'has_support_pin' => filled($s->support_software_pin),
                'reception_email' => $s->reception_email,
                'technical_contact' => $s->technical_contact,
                'production_since' => $s->production_since?->format('j M Y'),
                'certificate' => $s->certificate_meta,
                'habilitation' => collect(DianSetting::SCOPES)->map(fn ($label, $k) => $s->habilitationFor($k) + [
                    'label' => $label,
                    'required' => array_sum(config("dian.test_sets.$k")),
                    'detail' => config("dian.test_sets.$k"),
                    'missing' => $dian->missing($k),
                ]),
            ],
            'company' => $s->company->only(['nit', 'business_name', 'address', 'email', 'municipality_code', 'tax_regime', 'identification_type'])
                + ['dv' => $s->company->verificationDigit(), 'municipality' => $s->company->municipality?->name],
            'resolutions' => DocumentType::whereIn('type', ['FV', 'NC', 'ND', 'DS', 'NA'])->orderBy('type')->orderBy('code')->get()
                ->map(fn (DocumentType $d) => [
                    'id' => $d->id, 'label' => $d->label(), 'name' => $d->name, 'type' => $d->type,
                    'electronic' => DocumentType::ELECTRONIC_TYPES[$d->electronic_type] ?? $d->electronic_type,
                    'is_electronic' => $d->isElectronic(),
                    'resolution_number' => $d->resolution_number, 'prefix' => $d->prefix,
                    'range' => $d->range_from ? number_format($d->range_from, 0, '', '').' - '.number_format($d->range_to, 0, '', '') : null,
                    'valid_from' => $d->valid_from?->format('j M Y'), 'valid_to' => $d->valid_to?->format('j M Y'),
                    'consecutive' => $d->consecutive,
                    'has_key' => filled($d->technical_key),
                    'expired' => $d->valid_to && $d->valid_to->isPast(),
                ]),
            'transmissions' => DianTransmission::with('user:id,name')->latest('id')->limit(100)->get()->map(fn ($t) => [
                'id' => $t->id,
                'date' => $t->created_at->format('j M Y H:i:s'),
                'operation' => DianTransmission::OPERATIONS[$t->operation] ?? $t->operation,
                'scope' => DianSetting::SCOPES[$t->scope] ?? $t->scope,
                'document' => trim(($t->document_kind ?? '').' '.($t->document_number ?? '')),
                'track_id' => $t->track_id,
                'status_code' => $t->status_code,
                'message' => $t->status_message,
                'valid' => $t->is_valid,
                'simulated' => $t->simulated,
                'environment' => $t->environment,
                'ms' => $t->duration_ms,
                'user' => $t->user?->name,
            ]),
            'ranges' => session('dian_ranges', []),
            'history' => DianResolution::orderByDesc('resolution_date')->get()->map(fn (DianResolution $r) => [
                'id' => $r->id, 'kind' => $r->kind_label, 'linked' => $r->linked_document, 'resolution' => $r->resolution_number,
                'date' => $r->resolution_date?->format('Y/m/d'), 'prefix' => $r->prefix, 'from' => $r->range_from, 'to' => $r->range_to,
                'valid_from' => $r->valid_from?->format('Y/m/d'), 'valid_to' => $r->valid_to?->format('Y/m/d'),
                'expired' => $r->valid_to?->isPast() ?? false,
            ]),
        ]);
    }

    public function updateSoftware(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'software_id' => ['nullable', 'string', 'max:80'],
            'software_pin' => ['nullable', 'string', 'max:80'],
            'test_set_id' => ['nullable', 'string', 'max:80'],
            'payroll_software_id' => ['nullable', 'string', 'max:80'],
            'payroll_software_pin' => ['nullable', 'string', 'max:80'],
            'payroll_test_set_id' => ['nullable', 'string', 'max:80'],
            'support_software_id' => ['nullable', 'string', 'max:80'],
            'support_software_pin' => ['nullable', 'string', 'max:80'],
            'support_test_set_id' => ['nullable', 'string', 'max:80'],
            'reception_email' => ['nullable', 'email'],
            'technical_contact' => ['nullable', 'string', 'max:160'],
            'simulated' => ['boolean'],
            'environment' => ['required', Rule::in(['habilitacion', 'produccion'])],
        ]);
        foreach (['software_pin', 'payroll_software_pin', 'support_software_pin'] as $pin) {
            if (blank($data[$pin] ?? null)) {
                unset($data[$pin]);
            }
        }
        DianSetting::current()->update($data);

        return back()->with('success', 'La configuración del software se guardó correctamente.');
    }

    public function uploadCertificate(Request $request, CertificateService $certs): RedirectResponse
    {
        $request->validate([
            'certificate' => ['required', 'file', 'max:200'],
            'password' => ['required', 'string', 'max:120'],
        ], [], ['certificate' => 'certificado', 'password' => 'contraseña']);

        $file = $request->file('certificate');
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['p12', 'pfx'], true)) {
            return back()->withErrors(['certificate' => 'El certificado debe ser un archivo .p12 o .pfx.']);
        }
        $settings = DianSetting::current();
        try {
            $certs->store($settings, (string) file_get_contents($file->getRealPath()), $request->string('password'), $file->getClientOriginalName(), $settings->company);
        } catch (RuntimeException $e) {
            return back()->withErrors(['certificate' => $e->getMessage()]);
        }

        return redirect('/configuracion/facturacion-electronica?tab=certificado')->with('success', 'El certificado digital se cargó correctamente.');
    }

    public function testCertificate(CertificateService $certs): RedirectResponse
    {
        $settings = DianSetting::current();
        $password = bin2hex(random_bytes(6));
        $p12 = $certs->makeTestCertificate($settings->company, $password);
        $certs->store($settings, $p12, $password, 'certificado-prueba.p12', $settings->company);

        return redirect('/configuracion/facturacion-electronica?tab=certificado')->with('success', 'Se generó un certificado de prueba para el modo simulado.');
    }

    public function deleteCertificate(): RedirectResponse
    {
        $settings = DianSetting::current();
        if ($settings->certificate_path) {
            Storage::disk('local')->delete($settings->certificate_path);
        }
        $settings->update(['certificate_path' => null, 'certificate_password' => null, 'certificate_meta' => null]);

        return redirect('/configuracion/facturacion-electronica?tab=certificado')->with('success', 'Se eliminó el certificado digital.');
    }

    public function testConnection(): RedirectResponse
    {
        return $this->attempt(fn (DianService $d) => $d->testConnection()['message'], 'habilitacion');
    }

    public function queryRanges(): RedirectResponse
    {
        try {
            $ranges = DianService::forCurrent()->numberingRanges();
        } catch (RuntimeException $e) {
            return redirect('/configuracion/facturacion-electronica?tab=resoluciones')->with('error', $e->getMessage());
        }

        return redirect('/configuracion/facturacion-electronica?tab=resoluciones')
            ->with('dian_ranges', $ranges)
            ->with('success', 'La DIAN devolvió '.count($ranges).' rango(s) de numeración.');
    }

    public function applyRange(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'range' => ['required', 'array'],
            'range.resolution' => ['required', 'string'],
            'range.prefix' => ['required', 'string', 'max:10'],
            'range.from' => ['required', 'integer'],
            'range.to' => ['required', 'integer'],
            'range.resolution_date' => ['nullable', 'date'],
            'range.valid_from' => ['required', 'date'],
            'range.valid_to' => ['required', 'date'],
            'range.technical_key' => ['nullable', 'string'],
        ]);
        DianService::forCurrent()->applyRange(DocumentType::findOrFail($data['document_type_id']), $data['range']);

        return redirect('/configuracion/facturacion-electronica?tab=resoluciones')->with('success', 'La resolución quedó asociada al tipo de documento.');
    }

    public function runTestSet(string $scope): RedirectResponse
    {
        abort_unless(array_key_exists($scope, DianSetting::SCOPES), 404);

        return $this->attempt(function (DianService $d) use ($scope) {
            $d->startTestSet($scope);

            return 'Se empezó a enviar el set de pruebas. Puedes seguir el avance aquí abajo.';
        }, 'habilitacion');
    }

    public function testSetProgress(string $scope): \Illuminate\Http\JsonResponse
    {
        abort_unless(array_key_exists($scope, DianSetting::SCOPES), 404);

        return response()->json(DianService::forCurrent()->testSetProgress($scope));
    }

    public function activateProduction(): RedirectResponse
    {
        return $this->attempt(function (DianService $d) {
            $d->activateProduction();

            return 'Se activó el ambiente de producción.';
        }, 'habilitacion');
    }

    public function transmission(DianTransmission $transmission)
    {
        return response($transmission->response_body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Los tipos DS y NA no vienen de Siigo por API; se crean con el consecutivo siguiente al libro para poder asociarles la resolución. */
    private function ensureSupportTypes(): void
    {
        foreach (['DS' => ['Documento soporte', 'SupportDocument'], 'NA' => ['Nota de ajuste documento soporte', 'SupportDocumentAdjustment']] as $type => [$name, $electronic]) {
            if (DocumentType::where('type', $type)->exists()) {
                continue;
            }
            $used = (int) \Illuminate\Support\Facades\DB::table('ledger_lines')->where('document', 'like', "$type-1-%")
                ->max(\Illuminate\Support\Facades\DB::raw("cast(substring(document, 6) as unsigned)"));
            DocumentType::create([
                'company_id' => \App\Support\CurrentCompany::id(), 'type' => $type, 'code' => '1', 'name' => $name,
                'consecutive' => $used + 1, 'automatic_number' => true, 'electronic_type' => $electronic, 'active' => true,
            ]);
        }
    }

    private function attempt(callable $fn, string $tab): RedirectResponse
    {
        $to = '/configuracion/facturacion-electronica?tab='.$tab;
        try {
            return redirect($to)->with('success', $fn(DianService::forCurrent()));
        } catch (RuntimeException $e) {
            return redirect($to)->with('error', $e->getMessage());
        }
    }
}
