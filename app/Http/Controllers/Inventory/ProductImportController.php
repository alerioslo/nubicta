<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\Tax;
use App\Support\CurrentCompany;
use App\Support\DianUnitCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Productos y servicios > Importación / Subir productos desde excel, compatible con la plantilla de productos de Siigo para migrar datos. */
class ProductImportController extends Controller
{
    private const MAX = 500;

    private const REQUIRED = ['tipo', 'categoria', 'codigo', 'nombre', 'inventariable'];

    private const FIELDS = [
        'tipo' => 'Tipo de Producto',
        'categoria' => 'Categoría de Inventarios / Servicios',
        'codigo' => 'Código del Producto',
        'nombre' => 'Nombre del Producto / Servicio',
        'inventariable' => '¿Inventariable?',
        'visible' => 'Visible en facturas de venta',
        'stock_minimo' => 'Stock mínimo',
        'unidad' => 'Código Unidad de medida DIAN',
        'unidad_impresion' => 'Unidad de Medida Impresión Factura',
        'referencia' => 'Referencia de Fábrica',
        'barras' => 'Código de Barras',
        'descripcion' => 'Descripción Larga',
        'retencion' => 'Código Impuesto Retención',
        'cargo' => 'Código Impuesto Cargo',
        'cargo_dos' => 'Valor Impuesto Cargo Dos',
        'incluye_iva' => '¿Incluye IVA en Precio de Venta?',
        'arancel' => 'Código Arancelario',
        'marca' => 'Marca',
        'modelo' => 'Modelo',
    ];

    public function show(Request $request): Response
    {
        $preview = $request->input('paso') === '2' ? session('product_import') : null;

        return Inertia::render('Inventory/Products/Import', [
            'step' => $preview ? 2 : 1,
            'preview' => $preview,
            'fields' => collect(self::fields())->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'required' => in_array($key, self::REQUIRED)])->values(),
        ]);
    }

    /** Plantilla vacia con los campos elegidos (los obligatorios siempre van). */
    public function template(Request $request)
    {
        $fields = self::fields();
        $keys = array_values(array_filter(array_keys($fields), fn ($k) => in_array($k, self::REQUIRED) || in_array($k, (array) $request->input('campos', []))));
        $group = AccountGroup::query()->where('active', true)->orderBy('id')->first();
        $sample = ['tipo' => 'Producto', 'categoria' => $group?->siigo_id ?? $group?->id, 'codigo' => 'P-001', 'nombre' => 'Producto de ejemplo', 'inventariable' => 'Sí',
            'visible' => 'Sí', 'unidad' => '94', 'cargo' => Tax::query()->where('type', 'IVA')->value('code'), 'incluye_iva' => 'No', 'precio_1' => 10000];

        return ArrayExport::download('plantilla_productos.xlsx', array_map(fn ($k) => $fields[$k].(in_array($k, self::REQUIRED) ? ' (obligatorio)' : ''), $keys),
            [array_map(fn ($k) => $sample[$k] ?? '', $keys)], 'Productos');
    }

    /** Plantilla con los productos existentes para modificarlos. */
    public function existing()
    {
        $fields = self::fields();
        $lists = PriceList::query()->where('active', true)->orderBy('position')->pluck('id')->values();
        $rows = Product::query()->where('type', '!=', 'Combo')->with(['taxes', 'prices', 'accountGroup:id,siigo_id'])->orderBy('code')->lazy()->map(function (Product $p) use ($lists) {
            $row = [
                Product::TYPES[$p->type], $p->accountGroup?->siigo_id ?? $p->account_group_id, $p->code, $p->name, $p->stock_control ? 'Sí' : 'No', $p->visible_in_sales ? 'Sí' : 'No',
                (float) $p->min_stock, $p->unit_code, $p->unit_label, $p->reference, $p->barcode, $p->description,
                $p->taxes->whereIn('type', ['Retefuente', 'ReteIVA', 'ReteICA'])->first()?->code, $p->taxes->whereIn('type', ['IVA', 'Impoconsumo'])->first()?->code,
                $p->taxes->whereIn('type', ['IVA', 'Impoconsumo'])->skip(1)->first()?->pivot?->value, $p->tax_included ? 'Sí' : 'No',
            ];
            foreach ($lists as $id) {
                $row[] = (float) $p->prices->firstWhere('price_list_id', $id)?->value ?: '';
            }

            return [...$row, $p->tariff, $p->brand, $p->model];
        });

        return ArrayExport::download('productos_existentes.xlsx', array_map(fn ($k) => $fields[$k].(in_array($k, self::REQUIRED) ? ' (obligatorio)' : ''), array_keys($fields)), $rows, 'Productos');
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:1024', 'extensions:xlsx']],
            ['file.required' => 'Selecciona el archivo con tus productos', 'file.extensions' => 'El archivo debe ser .XLSX', 'file.max' => 'El archivo no puede pesar más de 1 MB']);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $head = array_map(fn ($h) => self::key((string) $h), array_shift($sheet) ?? []);
        $sheet = array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => trim((string) $v) !== ''));
        if ($missing = array_diff(self::REQUIRED, $head)) {
            $fields = self::fields();
            throw ValidationException::withMessages(['file' => 'Faltan columnas obligatorias: '.implode(', ', array_map(fn ($k) => $fields[$k], $missing))]);
        }
        if (! $sheet) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene registros']);
        }
        if (count($sheet) > self::MAX) {
            throw ValidationException::withMessages(['file' => 'Máximo '.self::MAX.' registros por archivo']);
        }

        $groups = AccountGroup::query()->get(['id', 'siigo_id', 'name']);
        $taxes = Tax::query()->where('active', true)->get(['id', 'code', 'type', 'name']);
        $units = app(DianUnitCatalog::class)->options();
        $existing = Product::query()->pluck('type', 'code');
        $lists = PriceList::query()->where('active', true)->orderBy('position')->pluck('id')->values();
        $seen = [];
        $rows = [];
        foreach ($sheet as $i => $cells) {
            $c = [];
            foreach ($head as $j => $key) {
                if ($key) {
                    $c[$key] = trim((string) ($cells[$j] ?? ''));
                }
            }
            $errors = [];
            $type = match (mb_strtolower($c['tipo'])) { 'producto', 'product' => 'Product', 'servicio', 'service' => 'Service', default => null };
            if (! $type) {
                $errors[] = 'El tipo debe ser Producto o Servicio';
            }
            $group = $groups->first(fn ($g) => in_array(mb_strtolower($c['categoria']), [(string) $g->siigo_id, (string) $g->id, mb_strtolower($g->name)], true));
            if (! $group) {
                $errors[] = "No existe la categoría \"{$c['categoria']}\"";
            }
            $code = $c['codigo'];
            if ($code === '' || mb_strlen($code) > 40) {
                $errors[] = 'El código es obligatorio (máximo 40 caracteres)';
            } elseif (isset($seen[$code])) {
                $errors[] = 'Código repetido en el archivo';
            } elseif (($existing[$code] ?? null) === 'Combo') {
                $errors[] = 'No se pueden importar combos';
            }
            $seen[$code] = true;
            if ($c['nombre'] === '' || mb_strlen($c['nombre']) > 250) {
                $errors[] = 'El nombre es obligatorio (máximo 250 caracteres)';
            }
            $unit = $c['unidad'] ?? '';
            if ($unit !== '' && ! isset($units[$unit])) {
                $errors[] = "No existe la unidad DIAN \"$unit\"";
            }
            $taxIds = [];
            foreach (['cargo' => ['IVA', 'Impoconsumo'], 'retencion' => ['Retefuente', 'ReteIVA', 'ReteICA']] as $k => $types) {
                if (($c[$k] ?? '') !== '') {
                    $tax = $taxes->first(fn ($t) => (string) $t->code === $c[$k] && in_array($t->type, $types));
                    $tax ? $taxIds[] = $tax->id : $errors[] = 'No existe el impuesto '.($k === 'cargo' ? 'cargo' : 'retención')." \"{$c[$k]}\"";
                }
            }
            $prices = [];
            foreach ($lists as $n => $listId) {
                $v = $c['precio_'.($n + 1)] ?? '';
                if ($v !== '') {
                    is_numeric($v) && $v >= 0 ? $prices[$listId] = (float) $v : $errors[] = 'Precio de venta '.($n + 1).' no es un número válido';
                }
            }
            foreach (['stock_minimo', 'cargo_dos'] as $k) {
                if (($c[$k] ?? '') !== '' && ! is_numeric($c[$k])) {
                    $errors[] = self::FIELDS[$k].' no es un número válido';
                }
            }
            $rows[] = [
                'row' => $i + 2, 'type' => $type, 'type_label' => $c['tipo'], 'group_id' => $group?->id, 'group' => $group?->name ?? $c['categoria'], 'code' => $code, 'name' => $c['nombre'],
                'stock_control' => $type === 'Product' && self::yes($c['inventariable']), 'data' => array_intersect_key($c, array_flip(['visible', 'stock_minimo', 'unidad', 'unidad_impresion', 'referencia', 'barras', 'descripcion', 'cargo_dos', 'incluye_iva', 'arancel', 'marca', 'modelo'])),
                'tax_ids' => array_key_exists('cargo', $c) || array_key_exists('retencion', $c) ? $taxIds : null, 'prices' => $prices,
                'action' => isset($existing[$code]) ? 'Actualizar' : 'Crear', 'errors' => implode('. ', $errors),
            ];
        }
        session(['product_import' => ['file' => $request->file('file')->getClientOriginalName(), 'rows' => $rows]]);

        return redirect('/productos/importar?paso=2');
    }

    public function confirm(): RedirectResponse
    {
        $data = session('product_import') ?? throw ValidationException::withMessages(['file' => 'Carga de nuevo el archivo']);
        $rows = collect($data['rows']);
        if ($rows->contains(fn ($r) => $r['errors'] !== '')) {
            throw ValidationException::withMessages(['file' => 'Corrige los errores del archivo y vuelve a cargarlo']);
        }
        $units = app(DianUnitCatalog::class)->options();
        $lines = ProductLine::query()->pluck('id', 'code')->all();
        $taxTypes = Tax::query()->pluck('type', 'id');
        DB::transaction(function () use ($rows, $units, $lines, $taxTypes) {
            foreach ($rows as $r) {
                $d = $r['data'];
                $product = Product::query()->firstOrNew(['company_id' => CurrentCompany::id(), 'code' => $r['code']]);
                $attrs = ['type' => $r['type'], 'account_group_id' => $r['group_id'], 'name' => $r['name'], 'stock_control' => $r['stock_control']];
                $map = ['referencia' => 'reference', 'barras' => 'barcode', 'descripcion' => 'description', 'arancel' => 'tariff', 'marca' => 'brand', 'modelo' => 'model'];
                foreach ($map as $k => $col) {
                    if (array_key_exists($k, $d)) {
                        $attrs[$col] = $d[$k] !== '' ? $d[$k] : null;
                    }
                }
                foreach (['visible' => 'visible_in_sales', 'incluye_iva' => 'tax_included'] as $k => $col) {
                    if (array_key_exists($k, $d) && $d[$k] !== '') {
                        $attrs[$col] = self::yes($d[$k]);
                    }
                }
                if (($d['stock_minimo'] ?? '') !== '') {
                    $attrs['min_stock'] = (float) $d['stock_minimo'];
                }
                if (($d['unidad'] ?? '') !== '' || ! $product->exists) {
                    $attrs['unit_code'] = ($d['unidad'] ?? '') ?: '94';
                    $label = $units[$attrs['unit_code']] ?? $attrs['unit_code'];
                    $attrs['unit_label'] = mb_strtolower(trim(explode('—', $label)[1] ?? $label));
                }
                if (($d['unidad_impresion'] ?? '') !== '') {
                    $attrs['unit_label'] = mb_substr($d['unidad_impresion'], 0, 60);
                }
                if (! $product->exists) {
                    $attrs += ['visible_in_sales' => true, 'tax_included' => false, 'min_stock' => 0, 'active' => true, 'tax_classification' => 'Taxed',
                        'product_line_id' => ($c = ProductLine::codeFor($r['code'], $lines)) ? $lines[$c] : null];
                }
                if ($r['type'] !== 'Product') {
                    $attrs['stock_control'] = false;
                }
                $product->fill($attrs)->save();

                if ($r['tax_ids'] !== null) {
                    $sync = [];
                    foreach ($r['tax_ids'] as $id) {
                        $sync[$id] = ['value' => in_array($taxTypes[$id], ['IVA', 'Impoconsumo']) && ($d['cargo_dos'] ?? '') !== '' ? (float) $d['cargo_dos'] : null];
                    }
                    $product->taxes()->sync($sync);
                }
                foreach ($r['prices'] as $listId => $value) {
                    $product->prices()->updateOrCreate(['price_list_id' => $listId], ['value' => $value]);
                }
            }
        });
        session()->forget('product_import');
        $created = $rows->where('action', 'Crear')->count();

        return redirect('/productos')->with('success', "Importación terminada: {$created} productos creados y ".($rows->count() - $created).' actualizados.');
    }

    /** Campos de la plantilla, con un precio de venta por cada lista activa (hasta 12). */
    private static function fields(): array
    {
        static $fields = null;
        if ($fields !== null) {
            return $fields;
        }
        $fields = [];
        foreach (self::FIELDS as $k => $label) {
            $fields[$k] = $label;
            if ($k === 'incluye_iva') {
                $count = min(12, PriceList::query()->where('active', true)->count());
                for ($n = 1; $n <= $count; $n++) {
                    $fields["precio_$n"] = "Precio de venta $n";
                }
            }
        }

        return $fields;
    }

    private static function key(string $header): ?string
    {
        $h = mb_strtolower(trim(str_replace('(obligatorio)', '', $header)));
        foreach (self::fields() as $k => $label) {
            if (mb_strtolower($label) === $h) {
                return $k;
            }
        }

        return null;
    }

    private static function yes(string $v): bool
    {
        return in_array(mb_strtolower(trim($v)), ['si', 'sí', 's', 'yes', 'y', '1', 'true', 'x'], true);
    }
}
