<?php

namespace App\Http\Controllers\ApiV1;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\DocumentType;
use App\Models\FixedAsset;
use App\Models\FrequentConcept;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Seller;
use App\Models\Tax;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Catálogos de consulta: lo que necesita una integración para armar documentos (ids de tipos, impuestos, formas de pago...). */
class CatalogController extends ApiBaseController
{
    public function documentTypes(Request $request): JsonResponse
    {
        $rows = DocumentType::query()->when($request->input('tipo'), fn ($q, $t) => $q->where('type', strtoupper($t)))
            ->when(! $request->boolean('incluir_inactivos'), fn ($q) => $q->where('active', true))->orderBy('type')->orderBy('code')->get();

        return response()->json(['data' => $rows->map(fn (DocumentType $t) => [
            'id' => $t->id, 'tipo' => $t->type, 'codigo' => $t->code, 'nombre' => $t->name, 'prefijo' => $t->prefix, 'consecutivo' => (int) $t->consecutive,
            'electronico' => $t->isElectronic(), 'numeracion_automatica' => (bool) $t->automatic_number, 'centro_costo_obligatorio' => (bool) $t->cost_center_mandatory,
            'resolucion' => $t->resolution_number ? ['numero' => $t->resolution_number, 'desde' => (int) $t->range_from, 'hasta' => (int) $t->range_to,
                'vigencia_desde' => $t->valid_from, 'vigencia_hasta' => $t->valid_to] : null,
            'activo' => (bool) $t->active,
        ])->values()]);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $type = strtoupper((string) $request->input('tipo_documento', ''));
        $rows = PaymentMethod::query()->where('active', true)->orderBy('name')->get()
            ->filter(fn ($m) => ! $type || empty($m->document_types) || in_array($type, (array) $m->document_types, true));

        return response()->json(['data' => $rows->map(fn ($m) => [
            'id' => $m->id, 'nombre' => $m->name, 'tipo' => $m->type, 'codigo_dian' => $m->means_code, 'cuenta_id' => $m->account_id,
            'maneja_vencimiento' => (bool) $m->due_date, 'tipos_documento' => $m->document_types ?? [],
        ])->values()]);
    }

    public function taxes(Request $request): JsonResponse
    {
        $rows = Tax::query()->where('active', true)->when($request->input('tipo'), fn ($q, $t) => $q->where('type', $t))->orderBy('type')->orderBy('percentage')->get();

        return response()->json(['data' => $rows->map(fn ($t) => [
            'id' => $t->id, 'codigo' => $t->code, 'nombre' => $t->name, 'tipo' => $t->type, 'porcentaje' => (float) $t->percentage,
            'valor_fijo' => (float) $t->fixed_value, 'base_minima' => (float) $t->base_minimum, 'codigo_dian' => $t->dian_code,
        ])->values()]);
    }

    public function priceLists(): JsonResponse
    {
        return response()->json(['data' => PriceList::query()->where('active', true)->orderBy('position')->get()
            ->map(fn ($p) => ['id' => $p->id, 'posicion' => (int) $p->position, 'nombre' => $p->name])->values()]);
    }

    public function warehouses(): JsonResponse
    {
        return response()->json(['data' => Warehouse::query()->where('active', true)->orderBy('id')->get()
            ->map(fn ($w) => ['id' => $w->id, 'nombre' => $w->name, 'direccion' => $w->address, 'responsable' => $w->responsible, 'principal' => (bool) $w->is_default])->values()]);
    }

    public function users(): JsonResponse
    {
        return response()->json(['data' => User::query()->where('active', true)->orderBy('name')->get()
            ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name, 'identificacion' => $u->identification, 'correo' => $u->email, 'vendedor' => (bool) $u->is_seller])->values()]);
    }

    public function sellers(): JsonResponse
    {
        return response()->json(['data' => Seller::query()->where('active', true)->orderBy('name')->get()
            ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->name, 'identificacion' => $s->identification, 'correo' => $s->email, 'comision' => (float) $s->commission_percentage, 'usuario_id' => $s->user_id])->values()]);
    }

    public function costCenters(): JsonResponse
    {
        return response()->json(['data' => CostCenter::query()->where('active', true)->orderBy('code')->get()
            ->map(fn ($c) => ['id' => $c->id, 'codigo' => $c->code, 'nombre' => $c->name, 'padre_id' => $c->parent_id])->values()]);
    }

    public function accountGroups(): JsonResponse
    {
        return response()->json(['data' => AccountGroup::query()->where('active', true)->orderBy('name')->get()
            ->map(fn ($g) => ['id' => $g->id, 'nombre' => $g->name, 'cuenta_inventario_id' => $g->inventory_account_id, 'cuenta_ingreso_id' => $g->income_account_id, 'cuenta_costo_id' => $g->cost_account_id])->values()]);
    }

    public function fixedAssets(): JsonResponse
    {
        return response()->json(['data' => FixedAsset::query()->where('active', true)->orderBy('code')->get()
            ->map(fn ($a) => ['id' => $a->id, 'codigo' => $a->code, 'nombre' => $a->name, 'grupo_id' => $a->fixed_asset_group_id])->values()]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $page = Account::query()->where('active', true)
            ->when($request->input('codigo'), fn ($q, $c) => $q->where('code', 'like', $c.'%'))
            ->when($request->input('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "$s%")->orWhere('name', 'like', "%$s%")))
            ->when($request->boolean('solo_movimiento'), fn ($q) => $q->where('transactional', true))
            ->orderBy('code')->paginate($this->perPage($request));

        return $this->paged($page, fn ($a) => [
            'id' => $a->id, 'codigo' => $a->code, 'nombre' => $a->name, 'nivel' => (int) $a->level, 'naturaleza' => $a->nature, 'movimiento' => (bool) $a->transactional,
            'requiere_tercero' => (bool) $a->requires_third_party, 'requiere_centro_costo' => (bool) $a->requires_cost_center,
            'detalle' => $a->due_type ? ['cartera' => 'cuentas_por_cobrar', 'proveedores' => 'cuentas_por_pagar'][$a->due_type] ?? $a->due_type : null,
        ]);
    }

    public function cities(Request $request): JsonResponse
    {
        $rows = DB::table('municipalities')->when($request->input('departamento'), fn ($q, $d) => $q->where('department_code', $d))
            ->when($request->input('q'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))->orderBy('code')->get();

        return response()->json(['data' => $rows->map(fn ($m) => ['codigo' => $m->code, 'departamento' => $m->department_code, 'nombre' => $m->name])->values()]);
    }

    public function concepts(Request $request): JsonResponse
    {
        return response()->json(['data' => FrequentConcept::query()->where('active', true)->when($request->input('tipo'), fn ($q, $k) => $q->where('kind', $k))->orderBy('name')->get()
            ->map(fn ($c) => ['id' => $c->id, 'tipo' => $c->kind, 'nombre' => $c->name, 'cuenta_id' => $c->account_id])->values()]);
    }

    public function bankAccounts(): JsonResponse
    {
        return response()->json(['data' => BankAccount::query()->where('active', true)->orderBy('name')->get()
            ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->name, 'tipo' => $b->kind, 'banco' => $b->bank_name, 'cuenta_id' => $b->account_id])->values()]);
    }
}
