<?php

namespace App\Http\Controllers\ApiV1;

use App\Models\Product;
use App\Models\ProductComboItem;
use App\Models\ProductLine;
use App\Support\CurrentCompany;
use App\Support\DianUnitCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Productos y servicios: equivale a /v1/products de Siigo. */
class ProductApiController extends ApiBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeApi('inventario.ver');
        $page = Product::query()->search($request->input('q'))->with(['prices', 'taxes:id,name,type,percentage'])
            ->when($request->input('codigo'), fn ($q, $c) => $q->where('code', $c))
            ->when($request->input('tipo'), fn ($q, $t) => $q->where('type', ApiPresenter::internal($t, ApiPresenter::PRODUCT_TYPES)))
            ->when($request->input('grupo_inventario_id'), fn ($q, $g) => $q->where('account_group_id', $g))
            ->when($request->has('activo'), fn ($q) => $q->where('active', $request->boolean('activo')))
            ->when($request->input('creado_desde'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('actualizado_desde'), fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->orderBy('code')->paginate($this->perPage($request));

        return $this->paged($page, fn (Product $p) => ApiPresenter::product($p));
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeApi('inventario.ver');

        return response()->json(ApiPresenter::product(Product::query()->with(['prices', 'taxes:id,name,type,percentage'])->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeApi('inventario.crear');
        $product = DB::transaction(function () use ($request) {
            [$attrs, $relations] = $this->validated($request->all());
            $product = Product::create($attrs);
            $this->sync($product, $relations);

            return $product;
        });

        return response()->json(ApiPresenter::product($product->fresh(['prices', 'taxes'])), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeApi('inventario.editar');
        $product = Product::query()->with(['prices', 'taxes:id'])->findOrFail($id);
        DB::transaction(function () use ($request, $product) {
            $current = [
                'codigo' => $product->code, 'nombre' => $product->name, 'tipo' => $product->type, 'grupo_inventario_id' => $product->account_group_id,
                'unidad_dian' => $product->unit_code, 'inventariable' => $product->stock_control, 'visible_en_ventas' => $product->visible_in_sales,
                'clasificacion_tributaria' => $product->tax_classification, 'iva_incluido' => $product->tax_included, 'referencia' => $product->reference,
                'descripcion' => $product->description, 'codigo_barras' => $product->barcode, 'marca' => $product->brand, 'modelo' => $product->model,
                'arancel' => $product->tariff, 'existencia_minima' => (float) $product->min_stock, 'activo' => $product->active,
            ];
            [$attrs, $relations] = $this->validated(array_merge($current, $request->all()), $product);
            $product->update($attrs);
            $this->sync($product, array_intersect_key($relations, array_flip(array_intersect(['impuestos', 'precios', 'componentes'], array_keys($request->all())))));
        });

        return response()->json(ApiPresenter::product($product->fresh(['prices', 'taxes'])));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeApi('inventario.eliminar');
        $product = Product::query()->findOrFail($id);
        $used = $product->movements()->exists() || ProductComboItem::where('product_id', $product->id)->exists()
            || DB::table('sales_document_items')->where('product_id', $product->id)->exists()
            || DB::table('purchase_document_items')->where('product_id', $product->id)->exists()
            || DB::table('journal_lines')->where('product_id', $product->id)->exists();
        if ($used) {
            return response()->json(['message' => "El producto {$product->code} tiene documentos o movimientos; inactívalo con PUT /productos/{$product->id} y \"activo\": false."], 409);
        }
        DB::transaction(function () use ($product) {
            $product->taxes()->detach();
            $product->prices()->delete();
            $product->comboItems()->delete();
            $product->delete();
        });

        return response()->json(['mensaje' => "Se eliminó el producto {$product->code}."]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function validated(array $input, ?Product $product = null): array
    {
        $companyId = CurrentCompany::id();
        $input['tipo'] = ApiPresenter::internal($input['tipo'] ?? null, ApiPresenter::PRODUCT_TYPES);
        $input['clasificacion_tributaria'] = ApiPresenter::internal($input['clasificacion_tributaria'] ?? null, ApiPresenter::TAX_CLASSES);
        $data = Validator::make($input, [
            'codigo' => ['required', 'string', 'max:40', Rule::unique('products', 'code')->where('company_id', $companyId)->ignore($product?->id)],
            'nombre' => ['required', 'string', 'max:250'],
            'tipo' => ['nullable', Rule::in(array_keys(Product::TYPES))],
            'grupo_inventario_id' => ['required', Rule::exists('account_groups', 'id')->where('company_id', $companyId)],
            'unidad_dian' => ['nullable', 'string', 'max:6'],
            'inventariable' => ['nullable', 'boolean'],
            'visible_en_ventas' => ['nullable', 'boolean'],
            'clasificacion_tributaria' => ['nullable', Rule::in(array_keys(Product::TAX_CLASSIFICATIONS))],
            'iva_incluido' => ['nullable', 'boolean'],
            'referencia' => ['nullable', 'string', 'max:250'],
            'descripcion' => ['nullable', 'string', 'max:4000'],
            'codigo_barras' => ['nullable', 'string', 'max:60'],
            'marca' => ['nullable', 'string', 'max:120'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'arancel' => ['nullable', 'string', 'max:20'],
            'existencia_minima' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
            'impuestos' => ['nullable', 'array'],
            'impuestos.*' => [Rule::exists('taxes', 'id')->where('company_id', $companyId)],
            'precios' => ['nullable', 'array'],
            'precios.*.lista_precio_id' => ['required', Rule::exists('price_lists', 'id')->where('company_id', $companyId)],
            'precios.*.valor' => ['required', 'numeric', 'min:0'],
            'componentes' => [Rule::requiredIf(($input['tipo'] ?? null) === 'Combo'), 'nullable', 'array'],
            'componentes.*.producto_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)->where('type', 'Product')],
            'componentes.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ], [
            'codigo.unique' => 'Ya existe un producto con este código.',
            'grupo_inventario_id.required' => 'Indica el grupo de inventario (GET /grupos-inventario).',
            'componentes.required' => 'Un combo necesita al menos un componente.',
        ])->validate();

        $type = $data['tipo'] ?? 'Product';
        $unit = $data['unidad_dian'] ?? '94';
        $label = app(DianUnitCatalog::class)->options()[$unit] ?? $unit;
        $lines = ProductLine::pluck('id', 'code')->all();
        $line = ProductLine::codeFor($data['codigo'], $lines);

        return [[
            'code' => $data['codigo'], 'name' => $data['nombre'], 'type' => $type, 'account_group_id' => $data['grupo_inventario_id'],
            'product_line_id' => $product?->product_line_id ?? ($line ? $lines[$line] : null),
            'unit_code' => $unit, 'unit_label' => mb_strtolower(trim(explode('—', $label)[1] ?? $label)),
            'stock_control' => $type === 'Product' && (bool) ($data['inventariable'] ?? true), 'visible_in_sales' => (bool) ($data['visible_en_ventas'] ?? true),
            'tax_classification' => $data['clasificacion_tributaria'] ?? 'Taxed', 'tax_included' => (bool) ($data['iva_incluido'] ?? false),
            'reference' => $data['referencia'] ?? null, 'description' => $data['descripcion'] ?? null, 'barcode' => $data['codigo_barras'] ?? null,
            'brand' => $data['marca'] ?? null, 'model' => $data['modelo'] ?? null, 'tariff' => $data['arancel'] ?? null,
            'min_stock' => $data['existencia_minima'] ?? 0, 'active' => (bool) ($data['activo'] ?? true),
        ], [
            'impuestos' => $data['impuestos'] ?? [], 'precios' => $data['precios'] ?? [], 'componentes' => $data['componentes'] ?? [],
        ]];
    }

    private function sync(Product $product, array $relations): void
    {
        if (array_key_exists('impuestos', $relations)) {
            $product->taxes()->sync($relations['impuestos']);
        }
        if (array_key_exists('precios', $relations)) {
            $product->prices()->delete();
            foreach ($relations['precios'] as $p) {
                $product->prices()->create(['price_list_id' => $p['lista_precio_id'], 'value' => $p['valor']]);
            }
        }
        if (array_key_exists('componentes', $relations) && in_array($product->type, ['Combo', 'Product'], true)) {
            $product->comboItems()->delete();
            foreach (collect($relations['componentes'])->groupBy('producto_id') as $productId => $rows) {
                if ((int) $productId !== $product->id) {
                    $product->comboItems()->create(['company_id' => $product->company_id, 'product_id' => $productId, 'quantity' => $rows->sum('cantidad')]);
                }
            }
        }
    }
}
