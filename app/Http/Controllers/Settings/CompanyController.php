<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Municipality;
use App\Support\ColombianNit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    /** Tributos DIAN (tabla 3.2.2 del anexo tecnico) que muestra Siigo en el perfil. */
    public const TRIBUTES = ['01' => 'IVA', '04' => 'INC', 'ZA' => 'IVA e INC', 'ZZ' => 'No aplica'];

    public function edit(Request $request): Response
    {
        $company = $request->user()->company;

        return Inertia::render('Settings/Company', [
            'company' => $company,
            'logoUrl' => $company->logo_path ? '/storage/'.$company->logo_path : null,
            'departments' => Department::orderBy('name')->get(['code', 'name']),
            'municipalities' => $company->department_code
                ? Municipality::where('department_code', $company->department_code)->orderBy('name')->get(['code', 'name'])
                : [],
            'catalogs' => [
                'identification_types' => config('erp.identification_types'),
                'tax_regimes' => config('erp.tax_regimes'),
                'fiscal_responsibilities' => config('erp.fiscal_responsibilities'),
                'tributes' => self::TRIBUTES,
            ],
            'municipalityName' => Municipality::where('code', $company->municipality_code)->value('name'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = $request->user()->company;
        $data = $request->validate([
            'person_type' => ['required', Rule::in(['juridica', 'natural'])],
            'identification_type' => ['required', Rule::in(array_keys(config('erp.identification_types')))],
            'nit' => ['required', 'regex:/^\d{5,15}$/', Rule::unique('companies')->ignore($company->id)],
            'business_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'required_if:person_type,natural', 'string', 'max:120'],
            'last_name' => ['nullable', 'required_if:person_type,natural', 'string', 'max:120'],
            'tax_regime' => ['required', Rule::in(array_keys(config('erp.tax_regimes')))],
            'fiscal_responsibilities' => ['array'],
            'fiscal_responsibilities.*' => [Rule::in(array_keys(config('erp.fiscal_responsibilities')))],
            'ciiu' => ['nullable', 'string', 'max:10'],
            'address' => ['required', 'string', 'max:255'],
            'department_code' => ['required', 'exists:departments,code'],
            'municipality_code' => ['required', 'exists:municipalities,code'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email'],
            'website' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'show_trade_name' => ['boolean'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone2' => ['nullable', 'string', 'max:30'],
            'phone_ext' => ['nullable', 'string', 'max:10'],
            'ica_rate' => ['nullable', 'string', 'max:20'],
            'sells_medicines_eps' => ['boolean'],
            'handles_aiu' => ['boolean'],
            'two_charge_taxes' => ['boolean'],
            'iva_withholding_agent' => ['boolean'],
            'ad_valorem' => ['boolean'],
            'tributes' => ['array'],
            'tributes.*' => [Rule::in(array_keys(self::TRIBUTES))],
            'foreign_currency' => ['boolean'],
            'legal_rep_first_name' => ['nullable', 'string', 'max:120'],
            'legal_rep_last_name' => ['nullable', 'string', 'max:120'],
            'legal_rep_identification_type' => ['nullable', Rule::in(array_keys(config('erp.identification_types')))],
            'legal_rep_identification' => ['nullable', 'string', 'max:20'],
            'has_partners' => ['boolean'],
        ], [], ['nit' => 'número de identificación', 'business_name' => 'razón social']);

        $data['dv'] = $data['identification_type'] === '31' ? ColombianNit::verificationDigit($data['nit']) : null;

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }
        unset($data['logo']);

        $company->update($data);

        return back()->with('success', 'Los datos de la empresa se guardaron correctamente.');
    }

    public function municipalities(string $department)
    {
        return Municipality::where('department_code', $department)->orderBy('name')->get(['code', 'name']);
    }
}
