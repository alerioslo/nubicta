<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\AccountsExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountController extends Controller
{
    private const CATEGORIES = [
        'Activos fijos', 'Caja - Bancos', 'Costo de ventas', 'Cuentas por cobrar', 'Cuentas por pagar', 'Gasto - Nómina',
        'Gastos', 'Ingresos', 'Inventarios', 'Orden', 'Otros activos', 'Otros gastos', 'Otros ingresos', 'Otros pasivos', 'Patrimonio',
    ];

    public function index(): Response
    {
        return Inertia::render('Accounting/Accounts', [
            'roots' => Account::whereNull('parent_id')->orderBy('code')->get()->map(fn ($a) => $this->node($a)),
            'categories' => self::CATEGORIES,
            'tabs' => \App\Http\Controllers\DashboardController::tabs('contabilidad'),
        ]);
    }

    public function children(Account $account): JsonResponse
    {
        return response()->json($account->children()->withCount('children')->get()->map(fn ($a) => $this->node($a)));
    }

    /** "Expandir todos los detalles": el plan de cuentas completo anidado. */
    public function tree(): JsonResponse
    {
        $byParent = Account::query()->orderBy('code')->get(['id', 'parent_id', 'code', 'name', 'level', 'transactional', 'active'])->groupBy(fn ($a) => (int) $a->parent_id);
        $build = function (int $parent) use (&$build, $byParent) {
            return ($byParent[$parent] ?? collect())->map(fn ($a) => ['children' => $build($a->id)] + $this->node($a))
                ->map(fn ($n) => ['has_children' => (bool) $n['children']] + $n)->values()->all();
        };

        return response()->json($build(0));
    }

    public function search(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code'));
        $name = trim((string) $request->input('name'));

        $rows = Account::query()
            ->when($code !== '', fn ($q) => $q->where('code', 'like', "$code%"))
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%$name%"))
            ->orderBy('code')->limit(200)->get();

        return response()->json($rows->map(fn ($a) => $this->node($a)));
    }

    public function show(Account $account): JsonResponse
    {
        return response()->json([...$account->toArray(), 'level_name' => Account::LEVEL_NAMES[$account->level], 'parent' => $account->parent?->label()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $parent = Account::findOrFail($data['parent_id']);

        if (! str_starts_with($data['code'], $parent->code) || strlen($data['code']) <= strlen($parent->code)) {
            throw ValidationException::withMessages(['code' => "El código debe iniciar con {$parent->code} y ser más largo."]);
        }
        if (Account::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe una cuenta con este código.']);
        }

        Account::create([
            ...$data,
            'level' => Account::levelForCode($data['code']),
            'nature' => Account::natureForCode($data['code']),
        ]);
        if ($parent->transactional) {
            $parent->update(['transactional' => false]);
        }

        return back()->with('success', 'Cuenta creada correctamente.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);
        unset($data['code'], $data['parent_id']);
        if ($account->children()->exists()) {
            $data['transactional'] = false;
        }
        $account->update($data);

        return back()->with('success', 'Cuenta actualizada correctamente.');
    }

    public function export(): BinaryFileResponse
    {
        return \App\Support\Download::export(new AccountsExport, 'Cuentas contables.xlsx', 'Listado de cuentas contables');
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        return $request->validate([
            'parent_id' => [$account ? 'nullable' : 'required', 'exists:accounts,id'],
            'code' => [$account ? 'nullable' : 'required', 'regex:/^\d{1,12}$/'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'due_type' => ['nullable', Rule::in(['cartera', 'proveedores'])],
            'fiscal_difference' => ['boolean'],
            'transactional' => ['boolean'],
            'requires_third_party' => ['boolean'],
            'requires_cost_center' => ['boolean'],
            'active' => ['boolean'],
        ], [], ['code' => 'código', 'name' => 'nombre']);
    }

    private function node(Account $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'level' => $a->level,
            'transactional' => $a->transactional,
            'active' => $a->active,
            'has_children' => isset($a->children_count) ? $a->children_count > 0 : ! $a->transactional,
        ];
    }
}
