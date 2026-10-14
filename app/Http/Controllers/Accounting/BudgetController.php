<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Models\Budget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Presupuesto contable por cuenta y mes, comparado con lo ejecutado en la contabilidad. */
class BudgetController extends Controller
{
    private const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function index(Request $request): Response
    {
        $term = trim((string) $request->input('q'));
        $rows = Budget::query()->withCount('lines')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$term%")->orWhere('year', $term)))
            ->orderByDesc('year')->orderBy('name')->get()->map(fn (Budget $b) => [
            $b->year, ['text' => $b->name, 'href' => "/contabilidad/presupuesto/{$b->id}"],
            ['actions' => [
                ['text' => 'Ver ejecución', 'href' => "/contabilidad/presupuesto/{$b->id}"],
                ['text' => 'Editar', 'href' => "/contabilidad/presupuesto/{$b->id}/editar"],
                ['text' => 'Eliminar', 'post' => "/contabilidad/presupuesto/{$b->id}/eliminar", 'confirm' => "¿Eliminar el presupuesto {$b->name}?", 'danger' => true],
            ]],
        ])->all();

        return Inertia::render('GenericList', [
            'view' => [
                'title' => 'Contabilidad', 'tab' => 'Presupuesto contable', 'heading' => 'Presupuesto contable', 'url' => '/contabilidad/presupuesto',
                'intro' => 'Elabora un presupuesto contable detallado por año y mes. Así, podrás compararlo fácilmente con los resultados de la ejecución.',
                'search' => 'Buscar por Año o Nombre', 'search_only' => true, 'empty_button' => ['label' => 'Crear presupuesto', 'href' => '/contabilidad/presupuesto/crear'],
                'buttons' => $rows ? [['label' => 'Crear presupuesto', 'href' => '/contabilidad/presupuesto/crear']] : [],
                'columns' => ['Año', 'Nombre del presupuesto', 'Acciones'],
                'empty' => ['Aún no hay presupuestos', 'Crea un presupuesto para consultar las entradas y salidas de dinero en comparación con lo ejecutado.'],
            ],
            'rows' => $rows,
            'tabs' => DashboardController::tabs('contabilidad', 'Presupuesto contable'),
            'filters' => $request->only('q'),
        ]);
    }

    public function create(): Response
    {
        return $this->form(new Budget(['year' => now()->year]));
    }

    public function edit(Budget $budget): Response
    {
        return $this->form($budget->load('lines.account:id,code,name'));
    }

    public function store(Request $request): RedirectResponse
    {
        $budget = DB::transaction(function () use ($request) {
            [$data, $lines] = $this->validated($request);
            $budget = Budget::create($data + ['created_by' => Auth::id()]);
            $budget->lines()->createMany($lines);

            return $budget;
        });

        return redirect("/contabilidad/presupuesto/{$budget->id}")->with('success', 'Se creó el presupuesto.');
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        DB::transaction(function () use ($request, $budget) {
            [$data, $lines] = $this->validated($request);
            $budget->update($data);
            $budget->lines()->delete();
            $budget->lines()->createMany($lines);
        });

        return redirect("/contabilidad/presupuesto/{$budget->id}")->with('success', 'Se actualizó el presupuesto.');
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->delete();

        return redirect('/contabilidad/presupuesto')->with('success', 'Se eliminó el presupuesto.');
    }

    public function show(Request $request, Budget $budget)
    {
        $from = max(1, min(12, (int) $request->input('mes_desde', 1)));
        $to = max($from, min(12, (int) $request->input('mes_hasta', 12)));
        $variation = in_array($request->input('variacion'), ['porcentaje', 'valor'], true) ? $request->input('variacion') : 'ambos';
        $rows = $this->comparison($budget, $from, $to);
        $planned = array_sum(array_column($rows, 2));
        $executed = array_sum(array_column($rows, 3));
        $totals = ['Total', '', $planned, $executed, array_sum(array_column($rows, 4)), $planned != 0 ? round($executed / $planned * 100, 1).' %' : '-'];
        $columns = ['Código', 'Cuenta', 'Presupuestado', 'Ejecutado', 'Variación en valor', 'Variación en porcentaje'];
        $drop = ['porcentaje' => [4], 'valor' => [5], 'ambos' => []][$variation];
        $pick = fn (array $r) => array_values(array_diff_key($r, array_flip($drop)));
        [$columns, $totals, $rows] = [$pick($columns), $pick($totals), array_map($pick, $rows)];

        if ($request->boolean('excel')) {
            return ArrayExport::download("presupuesto_{$budget->year}.xlsx", $columns, [...$rows, $totals], $budget->name);
        }

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => "{$budget->name} ({$budget->year})", 'subtitle' => 'Presupuestado contra ejecutado en la contabilidad',
                'back' => '/contabilidad/presupuesto', 'action' => "/contabilidad/presupuesto/{$budget->id}", 'method' => 'get', 'submit' => 'Consultar',
                'fields' => [
                    ['name' => 'mes_desde', 'label' => 'Mes desde', 'type' => 'month', 'required' => true],
                    ['name' => 'mes_hasta', 'label' => 'Mes hasta', 'type' => 'month', 'required' => true],
                    ['name' => 'variacion', 'label' => 'Selecciona el tipo de variación en la ejecución presupuestal', 'type' => 'select', 'required' => true,
                        'options' => [['ambos', 'Mostrar variación en porcentaje y valor'], ['porcentaje', 'Mostrar variación en porcentaje'], ['valor', 'Mostrar variación en valor']]],
                ],
                'links' => [['label' => 'Descargar Excel', 'href' => "/contabilidad/presupuesto/{$budget->id}?mes_desde={$from}&mes_hasta={$to}&variacion={$variation}&excel=1"], ['label' => 'Editar presupuesto', 'href' => "/contabilidad/presupuesto/{$budget->id}/editar", 'inertia' => true]],
            ],
            'initial' => ['mes_desde' => $from, 'mes_hasta' => $to, 'variacion' => $variation],
            'preview' => ['columns' => $columns, 'money' => $variation === 'porcentaje' ? [2, 3] : [2, 3, 4], 'rows' => $rows, 'totals' => $totals],
        ]);
    }

    /** Ejecutado = movimiento del periodo segun la naturaleza de la cuenta (ingresos credito-debito, demas debito-credito), sin el cierre de año. */
    private function comparison(Budget $budget, int $from, int $to): array
    {
        $start = sprintf('%d-%02d-01', $budget->year, $from);
        $end = \Illuminate\Support\Carbon::create($budget->year, $to, 1)->endOfMonth()->toDateString();
        return $budget->lines()->with('account:id,code,name,nature')->get()->sortBy('account.code')->map(function ($l) use ($from, $to, $start, $end) {
            $planned = 0.0;
            for ($m = $from; $m <= $to; $m++) {
                $planned += (float) ($l->months[$m - 1] ?? 0);
            }
            $sum = DB::table('ledger_lines')->where('company_id', \App\Support\CurrentCompany::id())->where('account_code', 'like', $l->account->code.'%')
                ->whereBetween('date', [$start, $end])->where('document', 'not like', 'CC-998-%')
                ->selectRaw('coalesce(sum(debit),0) d, coalesce(sum(credit),0) c')->first();
            $executed = $l->account->nature === 'credito' ? (float) $sum->c - (float) $sum->d : (float) $sum->d - (float) $sum->c;
            $executed = round($executed, 2);

            return [$l->account->code, $l->account->name, round($planned, 2), $executed, round($planned - $executed, 2), $planned != 0 ? round($executed / $planned * 100, 1).' %' : '-'];
        })->values()->all();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ], ['name.required' => 'El nombre del presupuesto es obligatorio', 'lines.required' => 'Agrega al menos una cuenta']);
        $lines = collect($request->input('lines'))->filter(fn ($l) => ! empty($l['account_id']))->map(fn ($l) => [
            'account_id' => (int) $l['account_id'],
            'months' => array_map(fn ($i) => round((float) ($l["m$i"] ?? 0), 2), range(1, 12)),
        ]);
        if ($lines->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['lines' => 'Agrega al menos una cuenta con valores']);
        }
        if ($lines->pluck('account_id')->duplicates()->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['lines' => 'Hay cuentas repetidas en el presupuesto']);
        }

        return [['year' => $data['year'], 'name' => $data['name']], $lines->values()->all()];
    }

    private function form(Budget $budget): Response
    {
        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => $budget->exists ? 'Editar presupuesto' : 'Crear presupuesto', 'back' => '/contabilidad/presupuesto',
                'action' => $budget->exists ? "/contabilidad/presupuesto/{$budget->id}" : '/contabilidad/presupuesto', 'method' => $budget->exists ? 'put' : 'post',
                'info' => 'Registra por cada cuenta contable el valor presupuestado de cada mes. Para ingresos el ejecutado es crédito menos débito; para costos y gastos, débito menos crédito.',
                'fields' => [
                    ['name' => 'year', 'label' => 'Año', 'type' => 'year', 'required' => true],
                    ['name' => 'name', 'label' => 'Nombre del presupuesto', 'required' => true],
                ],
                'grid' => ['name' => 'lines', 'label' => 'Cuentas y valores por mes', 'add' => 'Agregar cuenta', 'columns' => [
                    ['name' => 'account_id', 'label' => 'Cuenta contable', 'type' => 'account', 'width' => 'min-w-[260px]'],
                    ...array_map(fn ($i) => ['name' => 'm'.($i + 1), 'label' => self::MONTHS[$i], 'type' => 'money'], range(0, 11)),
                ]],
            ],
            'initial' => [
                'year' => $budget->year, 'name' => $budget->name,
                'lines' => $budget->exists ? $budget->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'account_id_label' => "{$l->account?->code} - {$l->account?->name}",
                    ...collect(range(1, 12))->mapWithKeys(fn ($i) => ["m$i" => (float) ($l->months[$i - 1] ?? 0)])->all(),
                ])->all() : [],
            ],
        ]);
    }
}
