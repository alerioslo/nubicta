<?php

namespace App\Http\Controllers\Banks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\BankAccount;
use App\Services\Banks\BankReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function __construct(private readonly BankReconciler $reconciler) {}

    public function index(Request $request): Response
    {
        $banks = BankAccount::query()->where('active', true)->where('kind', '!=', 'caja')->with('account:id,code')->orderBy('id')->get();
        $bank = $banks->firstWhere('id', $request->integer('cuenta')) ?? $banks->first();
        $until = $request->input('corte') ?: ($bank ? (DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->max('date') ?? now()->toDateString()) : now()->toDateString());

        $summary = $bank ? $this->reconciler->summary($bank, $until) : null;
        $monthStart = substr($until, 0, 7).'-01';
        $matched = $bank ? DB::table('bank_statement_lines as s')->leftJoin('ledger_lines as l', 'l.id', '=', 's.ledger_line_id')
            ->where('s.bank_account_id', $bank->id)->whereNotNull('s.ledger_line_id')->whereBetween('s.date', [$monthStart, $until])
            ->orderByDesc('s.date')->orderByDesc('s.id')->limit(300)
            ->get(['s.id', 's.date', 's.description', 's.amount', 's.match_type', 'l.document', 'l.date as book_date', 'l.contact_name']) : collect();

        return Inertia::render('Banks/Reconciliation', [
            'tabs' => DashboardController::tabs('bancos', 'Conciliación bancaria'),
            'banks' => $banks->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'account' => $b->account?->code])->values(),
            'bank' => $bank?->id,
            'until' => $until,
            'summary' => $summary ? [
                ...collect($summary)->except('bank_only', 'book_only')->all(),
                'bank_only' => $summary['bank_only']->map(fn ($l) => [
                    'id' => $l->id, 'date' => $l->date, 'description' => $l->description, 'reference' => trim(($l->reference ?? '').' '.($l->document ?? '')),
                    'branch' => $l->branch, 'amount' => (float) $l->amount, 'suggest' => BankReconciler::suggest($l->description, (float) $l->amount),
                ])->values(),
                'book_only' => $summary['book_only']->map(fn ($l) => [
                    'id' => $l->id, 'date' => $l->date, 'document' => $l->document, 'contact' => trim(($l->identification ?? '').' '.($l->contact_name ?? '')),
                    'amount' => round((float) $l->debit - (float) $l->credit, 2),
                ])->values(),
            ] : null,
            'matched' => $matched->map(fn ($m) => [...(array) $m, 'amount' => (float) $m->amount])->values(),
            'statements' => $bank ? DB::table('bank_statements')->where('bank_account_id', $bank->id)->orderByDesc('id')->limit(12)
                ->get(['id', 'file_name', 'format', 'period_from', 'period_to', 'lines_count', 'opening_balance', 'closing_balance', 'created_at']) : [],
            'concepts' => collect(config('accounting.bank_concepts'))->map(fn ($c, $k) => ['key' => $k, 'label' => $c['label'], 'account' => $c['account']])->values(),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt,xlsx,xls,ods,prn'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ], ['file.required' => 'Selecciona el archivo del extracto', 'file.extensions' => 'El archivo debe ser Excel (xlsx/xls), CSV o TXT']);
        $bank = BankAccount::query()->findOrFail($request->integer('bank_account_id'));
        $file = $request->file('file');
        try {
            $r = $this->reconciler->import($bank, $file->getRealPath(), $file->getClientOriginalName(), $request->integer('year') ?: (int) now()->year);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $msg = "Se importaron {$r['new']} movimientos ({$r['format']})".($r['duplicated'] ? ", {$r['duplicated']} ya estaban importados" : '').". Conciliados automáticamente: {$r['matched']}.";

        return back()->with('success', $msg);
    }

    public function auto(Request $request): RedirectResponse
    {
        $bank = BankAccount::query()->findOrFail($request->integer('bank_account_id'));
        $range = DB::table('bank_statement_lines')->where('bank_account_id', $bank->id)->selectRaw('min(date) a, max(date) b')->first();
        $n = $range->a ? $this->reconciler->autoMatch($bank, $range->a, $range->b) : 0;

        return back()->with('success', "Conciliados automáticamente: $n movimientos.");
    }

    public function match(Request $request): RedirectResponse
    {
        $request->validate(['line_id' => ['required', 'integer'], 'ledger_line_id' => ['required', 'integer']]);

        return $this->run(fn () => $this->reconciler->match($request->integer('line_id'), $request->integer('ledger_line_id')), 'Movimiento conciliado.');
    }

    public function unmatch(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->reconciler->unmatch($request->integer('line_id')), 'Se deshizo el cruce.');
    }

    public function post(Request $request): RedirectResponse
    {
        $request->validate(['line_id' => ['required', 'integer'], 'concept' => ['required', 'string']]);
        $name = null;
        $response = $this->run(function () use ($request, &$name) {
            $name = $this->reconciler->post($request->integer('line_id'), $request->input('concept'), $request->integer('account_id') ?: null);
        }, '');

        return $name ? back()->with('success', "Se contabilizó $name y quedó conciliado.") : $response;
    }

    public function destroy(int $statement): RedirectResponse
    {
        $row = DB::table('bank_statements')->where('id', $statement)->first() ?? abort(404);
        BankAccount::query()->findOrFail($row->bank_account_id);
        DB::table('bank_statements')->where('id', $statement)->delete();

        return back()->with('success', "Se eliminó la importación {$row->file_name}. Los comprobantes contabilizados desde ella se conservan.");
    }

    private function run(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['line' => $e->getMessage()]);
        }

        return back()->with('success', $ok);
    }
}
