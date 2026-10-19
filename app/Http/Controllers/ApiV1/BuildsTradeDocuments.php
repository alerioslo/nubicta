<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\DocumentFormController;
use App\Models\DocumentType;
use App\Models\FixedAsset;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Tax;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\Numbering;
use App\Services\Documents\TradeRegistrar;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Traduce el JSON de la API al formulario interno de ventas y compras y registra con las mismas reglas de la pantalla. */
trait BuildsTradeDocuments
{
    /** @return array<string, mixed> datos para TradeRegistrar::register */
    protected function tradeData(array $input, string $kind, bool $purchase): array
    {
        Validator::make($input, [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.precio' => ['nullable', 'numeric', 'min:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'items.*.impuesto_id' => ['nullable', 'integer'],
            'items.*.retencion_id' => ['nullable', 'integer'],
            'items.*.descripcion' => ['nullable', 'string', 'max:500'],
            'pagos' => ['nullable', 'array'],
            'pagos.*.forma_pago_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'pagos.*.valor' => ['required', 'numeric', 'gt:0'],
            'pagos.*.vencimiento' => ['nullable', 'date_format:Y-m-d'],
            'credito' => ['nullable', 'boolean'],
            'vencimiento' => ['nullable', 'date_format:Y-m-d'],
            'retenciones' => ['nullable', 'array'],
            'retenciones.*' => ['integer', 'exists:taxes,id'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
            'vendedor_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'centro_costo_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'lista_precio_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'bodega_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'precios_con_iva' => ['nullable', 'boolean'],
            'descuento_en_porcentaje' => ['nullable', 'boolean'],
        ], [
            'items.required' => 'Agrega al menos un ítem.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        ])->validate();

        $contact = $this->contactFrom($input, $purchase ? 'proveedor' : 'cliente');
        $listId = $input['lista_precio_id'] ?? $contact->price_list_id;
        $items = [];
        foreach (array_values($input['items']) as $n => $item) {
            $product = $this->productFrom($item, $n);
            $row = ['quantity' => (float) $item['cantidad'], 'discount' => (float) ($item['descuento'] ?? 0), 'description' => $item['descripcion'] ?? null];
            if ($product) {
                $row['product_id'] = $product->id;
                $row['price'] = array_key_exists('precio', $item) ? (float) $item['precio']
                    : (float) ($product->prices->firstWhere('price_list_id', $listId)?->value ?? $product->prices->sortBy('price_list_id')->first()?->value ?? 0);
                $row['tax_id'] = array_key_exists('impuesto_id', $item) ? $item['impuesto_id'] : $product->taxes->whereIn('type', ['IVA', 'Impoconsumo'])->first()?->id;
            } elseif ($purchase && ! empty($item['cuenta_id'] ?? $item['cuenta'] ?? null)) {
                $row['account_id'] = ! empty($item['cuenta_id']) ? (int) $item['cuenta_id'] : (DB::table('accounts')->where('code', $item['cuenta'])->value('id')
                    ?? throw ValidationException::withMessages(["items.$n.cuenta" => "No existe la cuenta {$item['cuenta']}."]));
                $row['price'] = (float) ($item['precio'] ?? 0);
                $row['tax_id'] = $item['impuesto_id'] ?? null;
            } elseif ($purchase && ! empty($item['activo_fijo_id'])) {
                $row['fixed_asset_id'] = FixedAsset::query()->findOrFail((int) $item['activo_fijo_id'])->id;
                $row['price'] = (float) ($item['precio'] ?? 0);
                $row['tax_id'] = $item['impuesto_id'] ?? null;
            } else {
                throw ValidationException::withMessages(["items.$n" => $purchase
                    ? 'Cada ítem necesita "producto_id", "codigo", "cuenta" (o "cuenta_id") o "activo_fijo_id".'
                    : 'Cada ítem necesita "producto_id" o "codigo".']);
            }
            $row['withholding_id'] = $item['retencion_id'] ?? null;
            $items[] = $row;
        }

        $summary = [];
        foreach (Tax::query()->whereIn('id', $input['retenciones'] ?? [])->get() as $tax) {
            $summary[$tax->type] = $tax->id;
        }
        $payments = collect($input['pagos'] ?? [])->map(fn ($p) => ['method_id' => $p['forma_pago_id'], 'value' => $p['valor'], 'due_date' => $p['vencimiento'] ?? null])->all();

        return [
            'date' => $input['fecha'], 'contact_id' => $contact->id, 'items' => $items, 'summary_retentions' => $summary,
            'payments' => $payments, 'credit' => ! empty($input['credito']) || ! $payments, 'due_date' => $input['vencimiento'] ?? null,
            'observations' => $input['observaciones'] ?? null, 'seller_id' => $input['vendedor_id'] ?? null, 'cost_center_id' => $input['centro_costo_id'] ?? null,
            'price_list_id' => $listId, 'warehouse_id' => $input['bodega_id'] ?? null,
            'checks' => array_filter([
                'IVA / Impoconsumo incluido' => ! empty($input['precios_con_iva']),
                'Descuento en porcentaje' => ! $purchase || ($input['descuento_en_porcentaje'] ?? true),
            ]),
        ];
    }

    /** Registra el documento; si se indica uno existente lo reemplaza conservando su número (como "Editar" en la pantalla). */
    protected function registerTrade(string $formKey, array $data, ?BaseModel $replace = null): SalesDocument|PurchaseDocument
    {
        $form = DocumentFormController::FORMS[$formKey];
        $fallbacks = DocumentFormController::FALLBACK_TYPES[$form['doc']] ?? [];

        return $this->attempt(fn () => DB::transaction(function () use ($form, $data, $fallbacks, $replace) {
            if ($replace) {
                $data['document_type'] = $replace->document_type_id;
                [$number, $name] = app(DocumentReverser::class)->prepareEdit($replace);
                app(Numbering::class)->reuse($number, $name);
            }

            return app(TradeRegistrar::class)->register($form, $data, $fallbacks);
        }));
    }

    /** Envía a la DIAN si el tipo es electrónico; devuelve el mensaje o null si no aplica. */
    protected function emitIfElectronic(SalesDocument|PurchaseDocument $doc, bool $send): ?string
    {
        if (! DocumentType::query()->find($doc->document_type_id)?->isElectronic()) {
            return null;
        }

        return $send ? app(DocumentFormController::class)->emit($doc) : 'Quedó como borrador electrónico; envíalo con POST .../dian.';
    }
}
