<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Completa empleados y contratos con las plantillas exportadas de Siigo (contratos 1418, empleados 1905)
 * y con lo que dejo contabilizado Siigo en los comprobantes de nomina (CC-992): grupo, salario vigente,
 * fondos como terceros, auxilio de transporte, empleados activos y si la empresa esta exonerada de aportes.
 */
class ImportSiigoPayroll extends Command
{
    protected $signature = 'payroll:import-siigo {--company=1} {--contracts=storage/app/imports/plantilla_1418.xlsx} {--employees=storage/app/imports/plantilla_1905.xlsx} {--dry}';

    protected $description = 'Completa la configuracion de nomina con las plantillas y el libro contable de Siigo';

    public function handle(): int
    {
        $company = Company::findOrFail($this->option('company'));
        CurrentCompany::set($company->id);
        $dry = (bool) $this->option('dry');

        $contracts = $this->sheet(base_path($this->option('contracts')), 'Número de identificación');
        $people = $this->sheet(base_path($this->option('employees')), 'Número de documento');
        $history = $this->history($company->id);
        $latest = $history->max('date');

        $ids = collect(array_keys($contracts))->merge(array_keys($people))->merge($history->keys())
            ->merge(Employee::query()->with('contact:id,identification')->get()->pluck('contact.identification'))->filter()->unique();

        $rows = [];
        foreach ($ids as $id) {
            $contact = Contact::query()->where('identification', $id)->first();
            $c = $contracts[$id] ?? null;
            $p = $people[$id] ?? null;
            $h = $history[$id] ?? null;
            $employee = $contact ? Employee::query()->firstWhere('contact_id', $contact->id) : null;
            if (! $contact || (! $employee && ! $c)) {
                continue;
            }
            $employee ??= new Employee(['company_id' => $company->id, 'contact_id' => $contact->id, 'payment_frequency' => 'Mensual']);

            $data = [];
            if ($c) {
                $data += array_filter([
                    'contract_type' => $c['Tipo de contrato'] ?? null,
                    'start_date' => $this->date($c['Fecha inicio de contrato'] ?? null),
                    'contract_number' => $c['Número de contrato'] ?? null,
                    'position' => $c['Cargo'] ?? null,
                    'payroll_group' => trim(preg_replace('/^\d+\s*-\s*/', '', (string) ($c['Grupo de nómina'] ?? ''))) ?: null,
                    'contributor_type' => $c['Tipo de cotizante'] ?? null,
                    'contributor_subtype' => $c['Subtipo de Cotizante'] ?? null,
                    'eps' => $c['Fondo de salud'] ?? null,
                    'pension_fund' => $c['Fondo de pensión'] ?? null,
                    'severance_fund' => $c['Fondo de cesantías'] ?? null,
                    'arl' => $c['Fondo ARL'] ?? null,
                    'ccf' => $c['Caja de compensación'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $data['integral_salary'] = mb_strtolower((string) ($c['Salario integral'] ?? '')) === 'si';
                $data['health_rate'] = $this->number($c['% Salud'] ?? '0,04') ?: 0.04;
                $data['pension_rate'] = $this->number($c['% pensión'] ?? '0,04') ?: 0.04;
                $data['arl_class'] = $this->riskClass($c['Clase de riesgo'] ?? '');
                $data['arl_risk'] = ['I', 'II', 'III', 'IV', 'V'][$data['arl_class'] - 1];
                $data['salary'] = max((float) $employee->salary, $this->number($c['Sueldo'] ?? 0));
                $data['severance_contact_id'] = $this->contactByName($c['Fondo de cesantías'] ?? null);
                if (! empty($c['Fecha fin de contrato'])) {
                    $data['end_date'] = $this->date($c['Fecha fin de contrato']);
                }
            }
            if ($p) {
                $method = $p['Método de pago'] ?? null;
                $data += array_filter([
                    'payment_method' => $method,
                    'bank_name' => $p['Entidad Bancaria'] ?? null,
                    'bank_account_type' => ($p['Tipo de cuenta'] ?? '') === 'No aplica' ? null : ($p['Tipo de cuenta'] ?? null),
                    'bank_account' => $p['Número de cuenta'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $contactData = array_filter([
                    'email' => $contact->email ? null : ($p['Correo electrónico'] ?? null),
                    'mobile' => $contact->mobile ? null : ($p['Número de celular'] ?? null),
                    'address' => $contact->address ? null : ($p['Dirección de residencia'] ?? null),
                ]);
                if ($contactData && ! $dry) {
                    $contact->forceFill(array_intersect_key($contactData, array_flip(array_keys($contact->getAttributes()))))->save();
                }
            }
            if ($h) {
                $data['payroll_group'] = array_search($h['prefix'], Employee::GROUPS, true) ?: ($data['payroll_group'] ?? 'Administración');
                $data['salary'] = max($data['salary'] ?? (float) $employee->salary, $h['salary']);
                $data['transport_aid'] = $h['transport'];
                foreach (['eps_contact_id', 'pension_contact_id', 'arl_contact_id', 'ccf_contact_id'] as $k) {
                    $data[$k] = $h[$k] ?? ($data[$k] ?? $employee->{$k});
                }
                $data['severance_contact_id'] = $h['severance_contact_id'] ?? $data['severance_contact_id'] ?? null;
            }
            $recent = $h && $latest && Carbon::parse($h['date'])->diffInDays(Carbon::parse($latest)) <= 90;
            $ended = ($data['end_date'] ?? $employee->end_date) && Carbon::parse($data['end_date'] ?? $employee->end_date)->isPast();
            $data['active'] = $recent && ! $ended;
            $data['pay_mode'] = 'nomina';
            $data = array_filter($data, fn ($v) => $v !== null);

            $rows[] = [$id, $contact->name, $data['payroll_group'] ?? $employee->payroll_group, number_format($data['salary'] ?? $employee->salary, 0, ',', '.'),
                ($data['transport_aid'] ?? true) ? 'Si' : 'No', $data['active'] ? 'Activo' : 'Inactivo', $h['date'] ?? '-'];
            if (! $dry) {
                $employee->fill($data)->save();
            }
        }
        $this->table(['Identificacion', 'Empleado', 'Grupo', 'Salario', 'Aux. transp.', 'Estado', 'Ultima nomina Siigo'], $rows);

        $exempt = ! DB::table('ledger_lines')->where('company_id', $company->id)->where('document', 'like', 'CC-992-%')
            ->where('date', '>=', now()->subYear()->toDateString())->where(fn ($q) => $q->where('account_code', 'like', '5_0569%')
                ->orWhere('account_code', 'like', '5_0578%')->orWhere('account_code', 'like', '5_0575%'))->exists();
        $settings = (array) ($company->payroll_settings ?? []);
        $settings['exempt_health_parafiscal'] = $exempt;
        unset($settings['monthly_hours']);
        if (! $dry) {
            $company->forceFill(['payroll_settings' => $settings])->save();
        }
        $this->info('Empresa '.($exempt ? 'exonerada' : 'no exonerada').' de salud empleador, SENA e ICBF (art. 114-1 E.T.), segun los comprobantes de Siigo.');
        $this->info($dry ? 'Simulacion: no se guardo nada.' : 'Nomina actualizada.');

        return self::SUCCESS;
    }

    /** Ultimo comprobante de nomina completo de cada empleado en Siigo (los de jornaleros solo traen 2 lineas). */
    private function history(int $company)
    {
        $docs = DB::table('ledger_lines')->where('company_id', $company)->where('document', 'like', 'CC-992-%')
            ->groupBy('document')->havingRaw('count(*) >= 6')->selectRaw('document, max(date) as date')->get();
        $out = collect();
        foreach ($docs->sortBy('date') as $doc) {
            $lines = DB::table('ledger_lines')->where('company_id', $company)->where('document', $doc->document)->get();
            $salary = $lines->first(fn ($l) => preg_match('/^(51|52|72)0506/', $l->account_code));
            if (! $salary) {
                continue;
            }
            $id = $salary->identification;
            $by = fn (string $prefix) => ($l = $lines->first(fn ($l) => str_starts_with($l->account_code, $prefix))) ? $l->contact_id : null;
            $prev = $out[$id] ?? ['salary' => 0];
            $year = substr($doc->date, 0, 4);
            $out[$id] = [
                'date' => $doc->date,
                'prefix' => substr($salary->account_code, 0, 2),
                'salary' => $year === now()->format('Y') ? max($prev['salary'], (float) $salary->debit) : $prev['salary'],
                'transport' => $lines->contains(fn ($l) => preg_match('/^(51|52|72)0527/', $l->account_code)),
                'eps_contact_id' => $by('237005'), 'pension_contact_id' => $by('238030'), 'arl_contact_id' => $by('237006'), 'ccf_contact_id' => $by('237010'),
                'severance_contact_id' => ($l = $lines->first(fn ($l) => preg_match('/^(51|52|72)0530/', $l->account_code))) ? $l->contact_id : null,
            ];
        }

        return $out;
    }

    /** Filas de la plantilla indexadas por identificacion; los encabezados se normalizan quitando "(Obligatorio)". */
    private function sheet(string $path, string $key): array
    {
        if (! is_file($path)) {
            $this->warn("No se encontro $path");

            return [];
        }
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $header = null;
        $out = [];
        foreach ($rows as $row) {
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
            if (! $header) {
                if (collect($row)->contains(fn ($v) => is_string($v) && str_starts_with($v, $key))) {
                    $header = array_map(fn ($v) => trim(preg_replace('/\(Obligatorio\)/', '', (string) $v)), $row);
                }

                continue;
            }
            $assoc = [];
            foreach ($header as $i => $h) {
                if ($h !== '') {
                    $assoc[$h] = $row[$i] ?? null;
                }
            }
            $id = preg_replace('/\D/', '', (string) ($assoc[$key] ?? ''));
            if ($id !== '') {
                $out[$id] = $assoc;
            }
        }

        return $out;
    }

    private function contactByName(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $word = preg_replace('/\s+(S\.?A\.?S?|EPS)\.?$/i', '', $name);

        return Contact::query()->where('name', $name)->value('id')
            ?? Contact::query()->where('name', 'like', $word.'%')->orderByRaw('length(name)')->value('id')
            ?? Contact::query()->where('name', 'like', '%'.$word.'%')->orderByRaw('length(name)')->value('id');
    }

    private function riskClass(string $text): int
    {
        preg_match('/Categor[ií]a\s+([IV]+)/i', $text, $m);

        return ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5][strtoupper($m[1] ?? 'I')] ?? 1;
    }

    private function number(mixed $v): float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }

        return (float) str_replace(',', '.', str_replace('.', '', (string) $v));
    }

    private function date(mixed $v): ?string
    {
        if (! $v) {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }

        return Carbon::createFromFormat('d/m/Y', trim($v))->toDateString();
    }
}
