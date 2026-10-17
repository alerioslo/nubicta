<?php

namespace App\Http\Controllers\Banks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\BankAccount;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Cajas y bancos: "Saldos de cuentas bancarias / cajas". */
class BankController extends Controller
{
    public function index(): Response
    {
        $rows = $this->balances();

        return Inertia::render('Banks/Index', [
            'rows' => $rows,
            'total' => $rows->sum('balance'),
            'as_of' => BankAccount::max('opening_at'),
            'tabs' => DashboardController::tabs('bancos'),
            'year_start' => now()->startOfYear()->toDateString(),
            'today' => now()->toDateString(),
        ]);
    }

    public function excel()
    {
        $rows = $this->balances();

        return \App\Support\Download::array('saldos-cuentas-bancarias-cajas.xlsx', ['Código cuenta contable', 'Cuenta bancaria / caja', 'Saldo'],
            $rows->map(fn ($r) => [$r['code'], $r['name'], $r['balance']])->push(['', 'Total general', $rows->sum('balance')]), 'Saldos');
    }

    private function balances(): Collection
    {
        BankAccount::syncFromPaymentMethods(\App\Support\CurrentCompany::id());

        return BankAccount::with('account:id,code')->where('active', true)->get()
            ->sortBy(fn ($b) => $b->account->code)->values()
            ->map(fn (BankAccount $b) => [
                'id' => $b->id,
                'code' => $b->account->code,
                'name' => $b->name,
                'account_id' => $b->account_id,
                'balance' => $b->balance(),
            ]);
    }
}
