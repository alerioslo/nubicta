<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Configuración > Nómina > Importación: empleados, contratos y salarios con las plantillas de Siigo (máximo 500 registros). */
class PayrollImportController extends Controller
{
    public const KINDS = [
        'empleados' => ['title' => 'Importación de empleados', 'noun' => 'empleados', 'head' => [
            'Primer nombre (Obligatorio)', 'Segundo nombre ', 'Primer apellido (Obligatorio)', 'Segundo apellido ', 'Tipo de documento (Obligatorio)',
            'Número de documento (Obligatorio)', 'Correo electrónico (Obligatorio)', 'Número de celular (Obligatorio)', 'Pais de residencia (Obligatorio)',
            'Departamento de residencia (Obligatorio)', 'Ciudad de residencia (Obligatorio)', 'Dirección de residencia (Obligatorio)', 'Método de pago (Obligatorio)',
            'Entidad Bancaria', 'Tipo de cuenta', 'Número de cuenta', 'Número de telefono celular', 'Dirección de oficina (Obligatorio)', 'Pais de oficina (Obligatorio)',
            'Departamento de oficina (Obligatorio)', 'Ciudad de oficina (Obligatorio)',
        ]],
        'contratos' => ['title' => 'Importación de contratos', 'noun' => 'contratos', 'head' => [
            'Nombre del empleado', 'Número de identificación (Obligatorio)', 'Tipo de contrato (Obligatorio)', 'Fecha inicio de contrato (Obligatorio)',
            'Fecha fin de contrato', 'Sueldo (Obligatorio)', 'Salario integral', 'Número de contrato (Obligatorio)', 'Grupo de nómina (Obligatorio)', 'Cargo (Obligatorio)',
            'Centro de costo', 'Tipo de cotizante (Obligatorio)', 'Subtipo de Cotizante (Obligatorio)', 'Fondo de salud (Obligatorio)', '% Salud (Obligatorio)',
            'Fondo de pensión', '% pensión', 'Fondo ARL', 'Clase de riesgo', 'Codigo CIIU', 'Código', 'Caja de compensación', 'Fondo de cesantías',
            'Deducción de vivienda', 'Deducción medicina prepagada', 'Aportes voluntarios a fondos de pensiones obligatorias', 'Aporte voluntario de pensión (Renta exenta)',
            'Aporte voluntario AFC', 'Aplica deducción para dependientes',
        ]],
        'salarios' => ['title' => 'Importación de salarios', 'noun' => 'salarios', 'head' => [
            'Número de identificación (Obligatorio)', 'Nombre del empleado', 'Número de contrato', 'Nuevo sueldo (Obligatorio)', 'Fecha de aplicación (Obligatorio)',
        ]],
    ];

    private const ID_TYPES = ['registro civil' => '11', 'tarjeta de identidad' => '12', 'cédula de ciudadanía' => '13', 'cedula de ciudadania' => '13',
        'tarjeta de extranjería' => '21', 'cédula de extranjería' => '22', 'cedula de extranjeria' => '22', 'nit' => '31', 'pasaporte' => '41',
        'documento de identificación extranjero' => '42', 'pep' => '47', 'permiso especial de permanencia' => '47', 'ppt' => '48', 'permiso por protección temporal' => '48'];

    public function show(Request $request, string $kind): Response
    {
        $preview = $request->input('paso') === '3' ? session("payroll_import_$kind") : null;

        return Inertia::render('Payroll/Import', ['kind' => $kind, 'title' => self::KINDS[$kind]['title'], 'noun' => self::KINDS[$kind]['noun'], 'preview' => $preview]);
    }

    public function template(Request $request, string $kind)
    {
        $rows = $request->input('plantilla') === 'todos' ? $this->current($kind) : [];

        return ArrayExport::download('Plantilla de '.self::KINDS[$kind]['noun'].'.xlsx', self::KINDS[$kind]['head'], $rows, 'Plantilla de '.self::KINDS[$kind]['noun']);
    }

    private function current(string $kind): array
    {
        $employees = Employee::query()->with('contact')->get();

        return $employees->map(fn (Employee $e) => match ($kind) {
            'empleados' => [
                Str::before((string) $e->contact?->first_name, ' '), Str::after((string) $e->contact?->first_name, ' ') === (string) $e->contact?->first_name ? '' : Str::after((string) $e->contact?->first_name, ' '),
                Str::before((string) $e->contact?->last_name, ' '), Str::contains((string) $e->contact?->last_name, ' ') ? Str::after((string) $e->contact?->last_name, ' ') : '',
                array_search($e->contact?->identification_type, self::ID_TYPES, true) ? Str::ucfirst(array_search($e->contact?->identification_type, self::ID_TYPES, true)) : '',
                $e->contact?->identification, $e->contact?->email, $e->contact?->mobile ?: $e->contact?->phone, 'Colombia',
                DB::table('departments')->where('code', $e->contact?->department_code)->value('name'),
                DB::table('municipalities')->where('code', $e->contact?->municipality_code)->value('name'), $e->contact?->address,
                $e->payment_method, $e->bank_name, $e->bank_account_type, $e->bank_account, $e->contact?->mobile, $e->contact?->address, 'Colombia',
                DB::table('departments')->where('code', $e->contact?->department_code)->value('name'), DB::table('municipalities')->where('code', $e->contact?->municipality_code)->value('name'),
            ],
            'contratos' => [
                $e->contact?->name, $e->contact?->identification, $e->contract_type, $e->start_date?->format('d/m/Y'), $e->end_date?->format('d/m/Y'), (float) $e->salary,
                $e->integral_salary ? 'Sí' : 'No', $e->contract_number, $e->payroll_group, $e->position, CostCenter::query()->whereKey($e->cost_center_id)->value('code'),
                $e->contributor_type, $e->contributor_subtype, $e->eps, round((float) $e->health_rate * 100, 2), $e->pension_fund, round((float) $e->pension_rate * 100, 2),
                $e->arl, $e->arl_class, '', '', $e->ccf, $e->severance_fund, '', '', '', '', '', '',
            ],
            'salarios' => [$e->contact?->identification, $e->contact?->name, $e->contract_number, (float) $e->salary, now()->format('d/m/Y')],
        })->all();
    }

    public function upload(Request $request, string $kind): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls']],
            ['file.required' => 'Selecciona el archivo de Excel para importar', 'file.extensions' => 'El archivo debe ser de Excel (xlsx o xls)']);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getSheet(0)->toArray(null, false, false, false);
        array_shift($sheet);
        $sheet = array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => trim((string) $v) !== ''));
        if (! $sheet) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene registros']);
        }
        if (count($sheet) > 500) {
            throw ValidationException::withMessages(['file' => 'Se permiten hasta 500 registros por archivo']);
        }
        $rows = [];
        foreach ($sheet as $i => $c) {
            $c = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_pad($c, count(self::KINDS[$kind]['head']), null));
            [$data, $errors] = $this->{'parse'.ucfirst($kind)}($c);
            $rows[] = ['row' => $i + 2, 'identification' => $data['identification'] ?? '', 'name' => $data['label'] ?? '',
                'action' => $data['action'] ?? '', 'data' => $data, 'errors' => implode('. ', $errors)];
        }
        $dupes = collect($rows)->countBy('identification')->filter(fn ($n) => $n > 1);
        foreach ($rows as &$r) {
            if ($dupes->has($r['identification']) && $kind !== 'salarios') {
                $r['errors'] = trim($r['errors'].'. Identificación repetida en el archivo', '. ');
            }
        }
        session(["payroll_import_$kind" => ['file' => $request->file('file')->getClientOriginalName(), 'rows' => $rows]]);

        return redirect("/configuracion/nomina/importacion/$kind?paso=3");
    }

    public function confirm(string $kind): RedirectResponse
    {
        $data = session("payroll_import_$kind") ?? throw ValidationException::withMessages(['file' => 'Carga de nuevo el archivo']);
        $rows = collect($data['rows']);
        if ($rows->contains(fn ($r) => $r['errors'] !== '')) {
            throw ValidationException::withMessages(['file' => 'Corrige los errores del archivo y vuelve a cargarlo']);
        }
        DB::transaction(fn () => $rows->each(fn ($r) => $this->{'save'.ucfirst($kind)}($r['data'])));
        session()->forget("payroll_import_$kind");

        return redirect('/nomina/empleados')->with('success', 'Importación terminada: '.$rows->count().' '.self::KINDS[$kind]['noun'].' procesados.');
    }

    private function parseEmpleados(array $c): array
    {
        $errors = [];
        foreach ([0 => 'el primer nombre', 2 => 'el primer apellido', 4 => 'el tipo de documento', 5 => 'el número de documento', 6 => 'el correo electrónico',
            7 => 'el número de celular', 10 => 'la ciudad de residencia', 11 => 'la dirección de residencia', 12 => 'el método de pago'] as $i => $label) {
            if ((string) $c[$i] === '') {
                $errors[] = "Falta $label";
            }
        }
        $idType = self::ID_TYPES[mb_strtolower((string) $c[4])] ?? null;
        if ((string) $c[4] !== '' && ! $idType) {
            $errors[] = "Tipo de documento \"{$c[4]}\" no válido";
        }
        if ((string) $c[6] !== '' && ! filter_var($c[6], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El correo electrónico no es válido';
        }
        $city = $this->municipality((string) $c[10], (string) $c[9]);
        if ((string) $c[10] !== '' && ! $city) {
            $errors[] = "No se encontró la ciudad \"{$c[10]}\" en {$c[9]}";
        }
        $id = preg_replace('/\D/', '', (string) $c[5]);
        $exists = Contact::query()->where('identification', $id)->first();

        return [[
            'identification' => $id, 'label' => trim("{$c[0]} {$c[1]} {$c[2]} {$c[3]}"), 'action' => $exists ? 'Actualizar' : 'Crear',
            'first_name' => trim("{$c[0]} {$c[1]}"), 'last_name' => trim("{$c[2]} {$c[3]}"), 'identification_type' => $idType, 'email' => $c[6],
            'mobile' => (string) $c[7], 'department_code' => $city->department_code ?? null, 'municipality_code' => $city->code ?? null, 'address' => $c[11],
            'payment_method' => $c[12], 'bank_name' => $c[13] ?: null, 'bank_account_type' => $c[14] ?: null, 'bank_account' => (string) $c[15] ?: null,
        ], $errors];
    }

    private function saveEmpleados(array $d): void
    {
        $contact = Contact::query()->firstOrNew(['identification' => $d['identification']]);
        $contact->fill([
            'company_id' => CurrentCompany::id(), 'person_type' => 'natural', 'identification_type' => $d['identification_type'], 'first_name' => $d['first_name'],
            'last_name' => $d['last_name'], 'name' => trim("{$d['first_name']} {$d['last_name']}"), 'email' => $d['email'], 'mobile' => $d['mobile'],
            'address' => $d['address'], 'country_code' => 'CO', 'department_code' => $d['department_code'], 'municipality_code' => $d['municipality_code'],
            'is_employee' => true, 'active' => true,
        ])->save();
        $employee = Employee::query()->firstOrNew(['contact_id' => $contact->id]);
        $employee->fill(['company_id' => CurrentCompany::id(), 'payment_method' => $d['payment_method'], 'bank_name' => $d['bank_name'],
            'bank_account_type' => $d['bank_account_type'], 'bank_account' => $d['bank_account']]);
        if (! $employee->exists) {
            $employee->active = false;
        }
        $employee->save();
    }

    private function parseContratos(array $c): array
    {
        $errors = [];
        $id = preg_replace('/\D/', '', (string) $c[1]);
        $contact = $id ? Contact::query()->where('identification', $id)->first() : null;
        if (! $contact) {
            $errors[] = "No existe el empleado con identificación {$c[1]}. Impórtalo primero en Empleados";
        }
        $type = $this->contractType((string) $c[2]);
        if (! $type) {
            $errors[] = "Tipo de contrato \"{$c[2]}\" no válido";
        }
        $start = $this->date($c[3]);
        if (! $start) {
            $errors[] = 'La fecha de inicio de contrato no es válida';
        }
        $end = $this->date($c[4]);
        if ((string) $c[4] !== '' && (! $end || $end < $start)) {
            $errors[] = 'La fecha fin de contrato no es válida';
        }
        if (! is_numeric($c[5]) || $c[5] <= 0) {
            $errors[] = 'El sueldo es obligatorio';
        }
        if ((string) $c[7] === '') {
            $errors[] = 'Falta el número de contrato';
        }
        $group = collect(array_keys(Employee::GROUPS))->first(fn ($g) => Str::lower(Str::ascii($g)) === Str::lower(Str::ascii((string) $c[8])));
        if (! $group) {
            $errors[] = "Grupo de nómina \"{$c[8]}\" no válido (".implode(', ', array_keys(Employee::GROUPS)).')';
        }
        foreach ([9 => 'el cargo', 11 => 'el tipo de cotizante', 12 => 'el subtipo de cotizante', 13 => 'el fondo de salud', 14 => 'el % de salud'] as $i => $label) {
            if ((string) $c[$i] === '') {
                $errors[] = "Falta $label";
            }
        }
        $center = (string) $c[10] !== '' ? CostCenter::query()->where('code', $c[10])->value('id') : null;
        if ((string) $c[10] !== '' && ! $center) {
            $errors[] = "No existe el centro de costo \"{$c[10]}\"";
        }
        $arlClass = (string) $c[18] === '' ? 1 : (int) $c[18];
        if ($arlClass < 1 || $arlClass > 5) {
            $errors[] = 'La clase de riesgo va de 1 a 5';
        }

        return [[
            'identification' => $id, 'label' => $contact?->name ?? (string) $c[0], 'action' => Employee::query()->where('contact_id', $contact?->id)->exists() ? 'Actualizar' : 'Crear',
            'contact_id' => $contact?->id, 'contract_type' => $type, 'start_date' => $start, 'end_date' => $end, 'salary' => (float) $c[5],
            'integral_salary' => in_array(Str::lower(Str::ascii((string) $c[6])), ['si', 's', 'x', '1', 'true'], true), 'contract_number' => (string) $c[7],
            'payroll_group' => $group, 'position' => $c[9], 'cost_center_id' => $center, 'contributor_type' => $c[11], 'contributor_subtype' => $c[12],
            'eps' => $c[13], 'health_rate' => (float) $c[14] / 100, 'pension_fund' => $c[15] ?: null, 'pension_rate' => (float) ($c[16] ?? 0) / 100,
            'arl' => $c[17] ?: null, 'arl_class' => $arlClass, 'ccf' => $c[21] ?: null, 'severance_fund' => $c[22] ?: null,
        ], $errors];
    }

    private function saveContratos(array $d): void
    {
        $employee = Employee::query()->firstOrNew(['contact_id' => $d['contact_id']]);
        $employee->fill(['company_id' => CurrentCompany::id(), 'active' => ! $d['end_date'] || $d['end_date'] >= now()->toDateString(),
            ...collect($d)->except(['identification', 'label', 'action', 'contact_id'])->all()])->save();
        Contact::query()->whereKey($d['contact_id'])->update(['is_employee' => true]);
    }

    private function parseSalarios(array $c): array
    {
        $errors = [];
        $id = preg_replace('/\D/', '', (string) $c[0]);
        $contact = $id ? Contact::query()->where('identification', $id)->first() : null;
        $employee = $contact ? Employee::query()->where('contact_id', $contact->id)
            ->when((string) $c[2] !== '', fn ($q) => $q->where('contract_number', $c[2]))->first() : null;
        if (! $employee) {
            $errors[] = "No existe un contrato para la identificación {$c[0]}".((string) $c[2] !== '' ? " con número {$c[2]}" : '');
        }
        if (! is_numeric($c[3]) || $c[3] <= 0) {
            $errors[] = 'El nuevo sueldo es obligatorio';
        }
        $date = $this->date($c[4]);
        if (! $date) {
            $errors[] = 'La fecha de aplicación no es válida';
        }

        return [['identification' => $id, 'label' => $contact?->name ?? (string) $c[1], 'action' => $employee ? 'Actualizar sueldo de '.number_format((float) $employee->salary, 0, ',', '.') : '',
            'employee_id' => $employee?->id, 'salary' => (float) $c[3], 'date' => $date], $errors];
    }

    private function saveSalarios(array $d): void
    {
        Employee::query()->whereKey($d['employee_id'])->update(['salary' => $d['salary']]);
    }

    private function contractType(string $v): ?string
    {
        $v = Str::lower(Str::ascii($v));

        return match (true) {
            str_contains($v, 'indefinido') => 'Indefinido',
            str_contains($v, 'fijo') => 'Fijo',
            str_contains($v, 'obra') || str_contains($v, 'labor') => 'Obra o labor',
            str_contains($v, 'aprendiz') => 'Aprendizaje',
            str_contains($v, 'practic') => 'Prácticas',
            default => null,
        };
    }

    private function municipality(string $city, string $department): ?object
    {
        if ($city === '') {
            return null;
        }
        $norm = fn ($s) => Str::lower(Str::ascii(trim(preg_replace('/\s*d\.?\s*c\.?$/i', '', $s))));
        $depts = DB::table('departments')->get()->filter(fn ($d) => $department === '' || $norm($d->name) === $norm($department))->pluck('code');

        return DB::table('municipalities')->when($depts->isNotEmpty(), fn ($q) => $q->whereIn('department_code', $depts))->get()
            ->first(fn ($m) => $norm($m->name) === $norm($city));
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $f) {
            $d = \DateTime::createFromFormat("!$f", (string) $v);
            if ($d && $d->format($f) === (string) $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
