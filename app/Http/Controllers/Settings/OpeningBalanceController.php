<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Saldos iniciales de cartera, proveedores e inventario: comprobantes CC-999 traidos de Siigo y carga por Excel. */
class OpeningBalanceController extends Controller
{
    public const KINDS = [
        'cuentas-por-cobrar' => ['title' => 'Saldos iniciales cuentas por cobrar', 'accounts' => ['13']],
        'cuentas-por-pagar' => ['title' => 'Saldos iniciales cuentas por pagar', 'accounts' => ['22', '23']],
        'inventario' => ['title' => 'Saldos iniciales de inventario', 'accounts' => ['14']],
    ];

    public function show(Request $request, string $kind): Response
    {
        $def = self::KINDS[$kind];
        $q = DB::table('ledger_lines as g')->leftJoin('accounts as a', 'a.id', '=', 'g.account_id')
            ->where('g.company_id', CurrentCompany::id())->where('g.document', 'like', 'CC-999-%')
            ->where(fn ($w) => collect($def['accounts'])->each(fn ($p) => $w->orWhere('g.account_code', 'like', "$p%")))
            ->when(trim((string) $request->input('q')), fn ($w, $t) => $w->where(fn ($x) => $x->where('g.contact_name', 'like', "%$t%")->orWhere('g.identification', 'like', "$t%")));

        $totals = (clone $q)->selectRaw('count(*) n, sum(g.debit) debit, sum(g.credit) credit')->first();

        return Inertia::render('Settings/OpeningBalances', [
            'kind' => $kind,
            'title' => $def['title'],
            'kinds' => collect(self::KINDS)->map(fn ($k) => $k['title']),
            'rows' => $q->selectRaw('g.date, g.document, g.account_code, a.name account_name, g.identification, g.contact_name, g.debit, g.credit')
                ->orderBy('g.date')->orderBy('g.document')->orderBy('g.id')->paginate($request->integer('per_page', 50))->withQueryString(),
            'totals' => $totals,
            'q' => $request->input('q', ''),
        ]);
    }

    public function import(string $kind): RedirectResponse
    {
        return redirect("/contabilidad/saldos-iniciales/$kind");
    }
}
