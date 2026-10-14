<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Tax;
use App\Services\Accounting\OpeningBalances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Contabilidad > Mas procesos > Saldos iniciales (cuentas por cobrar, por pagar, inventario y otras cuentas). */
class OpeningBalanceController extends Controller
{
    private const INFO = [
        'cuentas-por-cobrar' => 'Aquí podrás cargar los saldos iniciales de tus cuentas por cobrar y anticipos de tus clientes',
        'cuentas-por-pagar' => 'Aquí podrás cargar los saldos iniciales de tus cuentas por pagar y anticipos de tus proveedores.',
        'inventario' => 'Ingresa los saldos iniciales de tu inventario, si aún no has creado tus productos puedes crearlos masivamente',
        'otras-cuentas' => 'Ingresa los saldos iniciales de las cuentas contables que no han sido incluidas en otros procesos como Activos fijos, Inventarios, Cartera y proveedores. Solo se podrán cargar valores en positivo.',
    ];

    public function create(string $kind): Response
    {
        return Inertia::render('Accounting/OpeningBalanceForm', [
            'kind' => $kind,
            'title' => OpeningBalances::KINDS[$kind],
            'info' => self::INFO[$kind],
            'operations' => OpeningBalances::OPERATIONS[$kind] ?? [],
            'costCenters' => CostCenter::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'taxes' => $kind === 'otras-cuentas' ? Tax::query()->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, string $kind, OpeningBalances $balances): RedirectResponse
    {
        $entry = $balances->store($kind, $request->all());

        return redirect("/documentos/comprobantes/{$entry->id}")->with('success', "Se contabilizaron los saldos iniciales en {$entry->name}.");
    }

    public function template(string $kind)
    {
        return ArrayExport::download('plantilla_'.str_replace('-', '_', $kind).'.xlsx', OpeningBalances::TEMPLATES[$kind], [], 'Saldos iniciales');
    }

    public function excel(Request $request, string $kind, OpeningBalances $balances): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv']], ['file.required' => 'Selecciona el archivo de Excel', 'file.extensions' => 'El archivo debe ser Excel (xlsx/xls) o CSV']);

        return response()->json($balances->parse($kind, $request->file('file')->getRealPath()));
    }
}
