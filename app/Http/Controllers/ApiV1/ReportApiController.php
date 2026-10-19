<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Accounting\LedgerController;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Balance de prueba (general y por tercero), cartera por cobrar, cuentas por pagar y existencias. */
class ReportApiController extends ApiBaseController
{
    public function trialBalance(Request $request): JsonResponse
    {
        return $this->balance($request, false);
    }

    public function trialBalanceByContact(Request $request): JsonResponse
    {
        return $this->balance($request, true);
    }

    public function receivables(Request $request): JsonResponse
    {
        $this->authorizeApi('ventas.ver');

        return $this->pending($request, SalesDocument::query(), 'cliente');
    }

    public function payables(Request $request): JsonResponse
    {
        $this->authorizeApi('compras.ver');

        return $this->pending($request, PurchaseDocument::query(), 'proveedor');
    }

    public function stock(Request $request): JsonResponse
    {
        $this->authorizeApi('inventario.ver');
        $q = DB::table('warehouse_stock')->join('products', 'products.id', '=', 'warehouse_stock.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stock.warehouse_id')
            ->where('products.company_id', CurrentCompany::id())
            ->when($request->filled('bodega_id'), fn ($q) => $q->where('warehouse_stock.warehouse_id', $request->integer('bodega_id')))
            ->when($request->filled('codigo'), fn ($q) => $q->where('products.code', $request->input('codigo')))
            ->when($request->filled('producto_id'), fn ($q) => $q->where('products.id', $request->integer('producto_id')))
            ->when(! $request->boolean('incluir_ceros'), fn ($q) => $q->where('warehouse_stock.quantity', '!=', 0))
            ->orderBy('products.code')->orderBy('warehouses.name')
            ->select('products.id as producto_id', 'products.code as codigo', 'products.name as nombre', 'warehouses.id as bodega_id', 'warehouses.name as bodega', 'warehouse_stock.quantity as cantidad');

        return $this->paged($q->paginate($this->perPage($request)), fn ($r) => ['cantidad' => (float) $r->cantidad] + (array) $r);
    }

    private function balance(Request $request, bool $byContact): JsonResponse
    {
        $this->authorizeApi('reportes.ver');
        $request->validate(['desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d'], 'nivel' => ['nullable', 'integer', 'between:1,5']]);
        $level = $request->integer('nivel', $byContact ? 5 : 4);
        $rows = app(LedgerController::class)->balances($request, $level, $byContact, ! $byContact);

        return response()->json([
            'desde' => $request->input('desde', date('Y').'-01-01'), 'hasta' => $request->input('hasta', date('Y-m-d')), 'nivel' => $level,
            'data' => array_map(fn ($r) => [
                'cuenta' => $r['code'], 'nombre' => $r['name'], 'nivel' => $r['level'],
                ...($byContact ? ['identificacion' => $r['identification'], 'tercero' => $r['contact']] : []),
                'saldo_inicial' => round($r['opening'], 2), 'debitos' => round($r['debit'], 2), 'creditos' => round($r['credit'], 2), 'saldo_final' => $r['balance'],
            ], $rows),
        ]);
    }

    private function pending(Request $request, $q, string $party): JsonResponse
    {
        $today = Carbon::parse($request->input('fecha_corte', date('Y-m-d')));
        $q->with('contact')->where('balance', '>', 0.004)->where('status', '!=', 'annulled')->whereNotIn('type', ['CT', 'RM', 'OC'])
            ->when($request->filled('tercero_id'), fn ($q) => $q->where('contact_id', $request->integer('tercero_id')))
            ->when($request->filled('identificacion'), fn ($q) => $q->whereHas('contact', fn ($c) => $c->where('identification', preg_replace('/\D/', '', (string) $request->input('identificacion')))))
            ->when($request->boolean('vencidas'), fn ($q) => $q->whereDate('due_date', '<', $today->toDateString()));

        $total = (clone $q)->sum('balance');

        return response()->json([
            ...json_decode($this->paged($q->orderBy('due_date')->paginate($this->perPage($request)), fn ($d) => [
                'id' => $d->id, 'tipo' => $d->type, 'numero' => $d->name, 'fecha' => $d->date?->toDateString(), 'vencimiento' => $d->due_date?->toDateString(),
                'dias_vencido' => $d->due_date && $d->due_date->lt($today) ? (int) $d->due_date->diffInDays($today) : 0,
                $party => ['id' => $d->contact_id, 'identificacion' => $d->contact?->identification, 'nombre' => $d->contact?->name],
                'total' => (float) $d->total, 'saldo' => (float) $d->balance,
            ])->getContent(), true),
            'saldo_total' => round((float) $total, 2),
        ]);
    }
}
