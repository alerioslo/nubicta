<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Pantalla "Impuestos" de Siigo: grilla editable en linea con las pestañas Impuestos y Autoretenciones. */
class TaxController extends Controller
{
    public const TYPES = ['IVA', 'Retefuente', 'ReteICA', 'ReteIVA', 'Impoconsumo', 'Bebidas azucaradas', 'Comestibles ultraprocesados'];

    private const DIAN_EXTRA = ['Bebidas azucaradas' => '34', 'Comestibles ultraprocesados' => '35'];

    private const ACCOUNTS = ['sales_account_id', 'purchases_account_id', 'sales_return_account_id', 'purchases_return_account_id'];

    public function index(Request $request): Response
    {
        $taxes = Tax::query()->orderBy('code')->orderBy('id')->get();
        $labels = DB::table('accounts')->whereIn('id', $taxes->flatMap(fn ($t) => array_map(fn ($c) => $t->$c, self::ACCOUNTS))->filter()->unique())
            ->get(['id', 'code', 'name'])->mapWithKeys(fn ($a) => [$a->id => "{$a->code} - {$a->name}"]);
        $used = $this->used();
        $row = fn (Tax $t) => [
            'id' => $t->id, 'active' => (bool) $t->active, 'code' => $t->code, 'name' => $t->name, 'type' => $t->type,
            'fixed_value' => (bool) $t->fixed_value, 'percentage' => (float) $t->percentage, 'used' => isset($used[$t->id]),
        ] + collect(self::ACCOUNTS)->flatMap(fn ($c) => [$c => $t->$c, str_replace('_id', '_label', $c) => $labels[$t->$c] ?? ''])->all();

        return Inertia::render('Settings/Taxes', [
            'taxes' => $taxes->where('type', '!=', 'Autorretencion')->map($row)->values(),
            'selfWithholdings' => $taxes->where('type', 'Autorretencion')->map($row)->values(),
            'types' => self::TYPES,
            'tab' => $request->input('tab') === 'autoretenciones' ? 'autoretenciones' : 'impuestos',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tax = new Tax;
        $this->fill($tax, $request);

        return back()->with('success', "Se creó el impuesto {$tax->name}.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $tax = Tax::query()->findOrFail($id);
        $this->fill($tax, $request);

        return back()->with('success', "Se actualizó el impuesto {$tax->name}.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $tax = Tax::query()->findOrFail($id);
        if (isset($this->used()[$tax->id])) {
            return back()->with('error', "El impuesto {$tax->name} ya tiene movimientos; desmárcalo de \"En uso\" en lugar de eliminarlo.");
        }
        $tax->delete();

        return back()->with('success', "Se eliminó el impuesto {$tax->name}.");
    }

    private function fill(Tax $tax, Request $request): void
    {
        $self = $request->input('type') === 'Autorretencion';
        $data = $request->validate([
            'active' => ['boolean'],
            'code' => ['required', 'integer', 'min:1', Rule::unique('taxes', 'code')->where('company_id', \App\Support\CurrentCompany::id())->ignore($tax->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in($self ? ['Autorretencion'] : self::TYPES)],
            'fixed_value' => ['boolean'],
            'percentage' => [$request->boolean('fixed_value') ? 'nullable' : 'required', 'numeric', $request->boolean('fixed_value') ? 'min:0' : 'gt:0', 'max:1000'],
            'sales_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'purchases_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'sales_return_account_id' => [$self ? 'nullable' : 'required', 'integer', 'exists:accounts,id'],
            'purchases_return_account_id' => [$self ? 'nullable' : 'required', 'integer', 'exists:accounts,id'],
        ], [
            'required' => 'Campo obligatorio', 'percentage.gt' => 'El valor debe ser mayor a cero', 'code.unique' => 'Ya existe un impuesto con este código',
        ]);
        $tax->fill([
            'active' => $data['active'] ?? true, 'code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'],
            'fixed_value' => ! $self && ($data['fixed_value'] ?? false), 'percentage' => (float) ($data['percentage'] ?? 0),
            'dian_code' => Tax::TYPES[$data['type']] ?? self::DIAN_EXTRA[$data['type']] ?? $tax->dian_code,
        ] + array_intersect_key($data, array_flip(self::ACCOUNTS)))->save();
    }

    /** @return array<int, true> impuestos con movimientos */
    private function used(): array
    {
        $ids = collect();
        foreach (['sales_document_item_taxes', 'purchase_document_item_taxes', 'sales_document_retentions', 'purchase_document_retentions', 'receipt_item_adjustments'] as $table) {
            $ids = $ids->merge(DB::table($table)->whereNotNull('tax_id')->distinct()->pluck('tax_id'));
        }

        return $ids->unique()->mapWithKeys(fn ($id) => [$id => true])->all();
    }
}
