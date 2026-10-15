<?php

namespace App\Http\Controllers\Contacts;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\ColombianNit;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Ventas/Compras > Crear cliente/proveedor ▾ > Importar clientes, proveedores o contactos: plantilla de Excel en dos pasos. */
class ContactImportController extends Controller
{
    public const KINDS = [
        'clientes' => ['title' => 'clientes', 'roles' => ['is_customer'], 'back' => '/ventas/clientes'],
        'proveedores' => ['title' => 'proveedores', 'roles' => ['is_supplier'], 'back' => '/compras/proveedores'],
        'contactos' => ['title' => 'contactos', 'roles' => [], 'back' => '/ventas/clientes'],
    ];

    private const HEAD = ['Tipo de persona (Natural / Jurídica)', 'Tipo de identificación (13 Cédula / 31 NIT / 22 Cédula de extranjería / 41 Pasaporte)', 'Identificación', 'Sucursal',
        'Nombres', 'Apellidos', 'Razón social', 'Nombre comercial', 'Dirección', 'Código ciudad (DANE)', 'Indicativo', 'Teléfono', 'Correo electrónico', 'Responsable de IVA (Si / No)', 'Estado (Activo / Inactivo)'];

    private const ROLE_HEAD = ['Cliente (Si / No)', 'Proveedor (Si / No)', 'Otro (Si / No)'];

    private const MAX = 2000;

    private const ID_TYPES = ['13', '22', '31', '41', '42', '21', '12', '11', '47', '48', '50', '91'];

    public function show(Request $request, string $kind): Response
    {
        $preview = $request->input('paso') === '2' ? session("contact_import_$kind") : null;

        return Inertia::render('Contacts/ContactImport', ['kind' => $kind, 'cfg' => self::KINDS[$kind], 'step' => $preview ? 2 : 1, 'preview' => $preview]);
    }

    public function template(string $kind)
    {
        $roles = $kind === 'contactos' ? ['Si', 'No', 'No'] : [];

        return ArrayExport::download("plantilla_$kind.xlsx", $this->head($kind), [
            ['Natural', '13', '12345678', '0', 'Pedro', 'Pérez', '', '', 'Calle 1 # 2-3', '41551', '57', '3001234567', 'correo@ejemplo.com', 'No', 'Activo', ...$roles],
            ['Jurídica', '31', '900123456', '0', '', '', 'Empresa Ejemplo SAS', 'Ejemplo', 'Carrera 4 # 5-6', '11001', '57', '6011234567', 'facturas@ejemplo.com', 'Si', 'Activo', ...$roles],
        ], ucfirst($kind));
    }

    public function upload(Request $request, string $kind): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv']], ['file.required' => 'Selecciona el archivo de Excel para importar', 'file.extensions' => 'El archivo debe ser Excel (xlsx/xls) o CSV']);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        array_shift($sheet);
        $sheet = array_values(array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => trim((string) $v) !== '')));
        if (! $sheet) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene registros']);
        }
        if (count($sheet) > self::MAX) {
            throw ValidationException::withMessages(['file' => 'Se permiten hasta '.self::MAX.' registros por archivo']);
        }
        $existing = Contact::query()->get(['id', 'identification', 'branch_office'])->keyBy(fn ($c) => $c->identification.'|'.(int) $c->branch_office);
        $yes = fn ($v) => in_array(mb_strtolower(trim((string) $v)), ['si', 'sí', 's', 'x', '1', 'true'], true);
        $seen = [];
        $rows = [];
        foreach ($sheet as $i => $c) {
            $c = array_map(fn ($v) => trim((string) $v), array_pad($c, count($this->head($kind)), ''));
            $juridica = str_starts_with(mb_strtolower($c[0]), 'j');
            $id = preg_replace('/\D/', '', $c[2]);
            $branch = (int) $c[3];
            $roles = $kind === 'contactos'
                ? array_keys(array_filter(['is_customer' => $yes($c[15]), 'is_supplier' => $yes($c[16]), 'is_other' => $yes($c[17])]))
                : self::KINDS[$kind]['roles'];
            $errors = [];
            if (! in_array($c[1], self::ID_TYPES, true)) {
                $errors[] = 'Tipo de identificación no válido';
            }
            if ($id === '' || strlen($id) > 20) {
                $errors[] = 'La identificación es obligatoria (solo números, máximo 20)';
            } elseif (isset($seen["$id|$branch"])) {
                $errors[] = 'Identificación repetida en el archivo';
            }
            if ($juridica ? $c[6] === '' : ($c[4] === '' || $c[5] === '')) {
                $errors[] = $juridica ? 'La razón social es obligatoria' : 'Nombres y apellidos son obligatorios';
            }
            if ($c[12] !== '' && ! filter_var($c[12], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Correo electrónico no válido';
            }
            if ($c[9] !== '' && ! preg_match('/^\d{5}$/', $c[9])) {
                $errors[] = 'El código de ciudad debe tener 5 dígitos (DANE)';
            }
            if (! $roles) {
                $errors[] = 'Indica si es cliente, proveedor u otro';
            }
            $seen["$id|$branch"] = true;
            $rows[] = [
                'row' => $i + 2, 'person_type' => $juridica ? 'juridica' : 'natural', 'identification_type' => $c[1], 'identification' => $id, 'branch_office' => $branch,
                'first_name' => $c[4], 'last_name' => $c[5], 'business_name' => $c[6], 'trade_name' => $c[7], 'address' => $c[8], 'city' => $c[9],
                'phone_indicative' => $c[10], 'phone' => $c[11], 'email' => $c[12], 'vat_responsible' => $yes($c[13]), 'active' => ! str_starts_with(mb_strtolower($c[14]), 'inac'),
                'roles' => $roles, 'name' => $juridica ? $c[6] : trim("$c[4] $c[5]"),
                'action' => isset($existing["$id|$branch"]) ? 'Actualizar' : 'Crear', 'errors' => implode('. ', $errors),
            ];
        }
        session(["contact_import_$kind" => ['file' => $request->file('file')->getClientOriginalName(), 'rows' => $rows]]);

        return redirect("/terceros/importar/$kind?paso=2");
    }

    public function confirm(string $kind): RedirectResponse
    {
        $data = session("contact_import_$kind") ?? throw ValidationException::withMessages(['file' => 'Carga de nuevo el archivo']);
        $rows = collect($data['rows']);
        if ($rows->contains(fn ($r) => $r['errors'] !== '')) {
            throw ValidationException::withMessages(['file' => 'Corrige los errores del archivo y vuelve a cargarlo']);
        }
        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $contact = Contact::query()->firstOrNew(['company_id' => CurrentCompany::id(), 'identification' => $r['identification'], 'branch_office' => $r['branch_office']]);
                $contact->fill(collect($r)->only(['person_type', 'identification_type', 'first_name', 'last_name', 'business_name', 'trade_name', 'address', 'phone_indicative', 'phone', 'email', 'vat_responsible', 'active'])
                    ->map(fn ($v) => $v === '' ? null : $v)->all());
                $contact->dv = ColombianNit::verificationDigit($r['identification']);
                if ($r['city'] !== '') {
                    $contact->forceFill(['country_code' => 'CO', 'department_code' => substr($r['city'], 0, 2), 'municipality_code' => $r['city']]);
                }
                foreach ($r['roles'] as $role) {
                    $contact->{$role} = true;
                }
                $contact->save();
            }
        });
        session()->forget("contact_import_$kind");
        $created = $rows->where('action', 'Crear')->count();

        return redirect(self::KINDS[$kind]['back'])->with('success', "Importación terminada: {$created} {$kind} creados y ".($rows->count() - $created).' actualizados.');
    }

    private function head(string $kind): array
    {
        return $kind === 'contactos' ? [...self::HEAD, ...self::ROLE_HEAD] : self::HEAD;
    }
}
