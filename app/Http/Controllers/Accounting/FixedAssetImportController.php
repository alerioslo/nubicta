<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\FixedAssetGroup;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Contabilidad > Mas procesos > Importar activo fijo: plantilla de Excel en dos pasos como Siigo. */
class FixedAssetImportController extends Controller
{
    private const HEAD = ['Código grupo de activo', 'Código activo fijo', 'Nombre', 'Estado (Activo / Inactivo)'];

    private const MAX = 500;

    public function show(Request $request): Response
    {
        $preview = $request->input('paso') === '2' ? session('fixed_asset_import') : null;

        return Inertia::render('Accounting/FixedAssetImport', [
            'step' => $preview ? 2 : 1,
            'preview' => $preview,
            'groups' => FixedAssetGroup::query()->orderBy('code')->get(['code', 'name']),
        ]);
    }

    public function template()
    {
        $group = FixedAssetGroup::query()->orderBy('code')->first();

        return ArrayExport::download('plantilla_activos_fijos.xlsx', self::HEAD, [[$group?->code ?? '1', 'AF-001', 'Computador portátil', 'Activo']], 'Activos fijos');
    }

    public function upload(Request $request): RedirectResponse
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
        $groups = FixedAssetGroup::query()->pluck('id', 'code');
        $existing = FixedAsset::query()->pluck('id', 'code');
        $seen = [];
        $rows = [];
        foreach ($sheet as $i => $c) {
            [$group, $code, $name, $state] = array_map(fn ($v) => trim((string) $v), array_pad($c, 4, ''));
            $errors = [];
            if (! isset($groups[$group])) {
                $errors[] = "No existe el grupo de activo \"$group\"";
            }
            if ($code === '' || mb_strlen($code) > 20) {
                $errors[] = 'El código es obligatorio (máximo 20 caracteres)';
            } elseif (isset($seen[$code])) {
                $errors[] = 'Código repetido en el archivo';
            }
            if ($name === '' || mb_strlen($name) > 160) {
                $errors[] = 'El nombre es obligatorio (máximo 160 caracteres)';
            }
            $seen[$code] = true;
            $rows[] = ['row' => $i + 2, 'group' => $group, 'code' => $code, 'name' => $name, 'active' => ! str_starts_with(mb_strtolower($state), 'inac'),
                'action' => isset($existing[$code]) ? 'Actualizar' : 'Crear', 'errors' => implode('. ', $errors)];
        }
        session(['fixed_asset_import' => ['file' => $request->file('file')->getClientOriginalName(), 'rows' => $rows]]);

        return redirect('/contabilidad/activos-fijos/importar?paso=2');
    }

    public function confirm(): RedirectResponse
    {
        $data = session('fixed_asset_import') ?? throw ValidationException::withMessages(['file' => 'Carga de nuevo el archivo']);
        $rows = collect($data['rows']);
        if ($rows->contains(fn ($r) => $r['errors'] !== '')) {
            throw ValidationException::withMessages(['file' => 'Corrige los errores del archivo y vuelve a cargarlo']);
        }
        $groups = FixedAssetGroup::query()->pluck('id', 'code');
        DB::transaction(function () use ($rows, $groups) {
            foreach ($rows as $r) {
                FixedAsset::query()->updateOrCreate(['company_id' => CurrentCompany::id(), 'code' => $r['code']], [
                    'fixed_asset_group_id' => $groups[$r['group']], 'name' => $r['name'], 'active' => $r['active'], 'modified_by' => Auth::user()?->name,
                ]);
            }
        });
        session()->forget('fixed_asset_import');
        $created = $rows->where('action', 'Crear')->count();

        return redirect('/configuracion/activos-fijos')->with('success', "Importación terminada: {$created} activos creados y ".($rows->count() - $created).' actualizados.');
    }
}
