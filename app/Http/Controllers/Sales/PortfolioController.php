<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Contact;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Pestanas "Clientes" (Ventas) y "Proveedores" (Compras) de Siigo: saldo de cartera por tercero. */
class PortfolioController extends Controller
{
    private const SIDES = [
        'clientes' => ['model' => SalesDocument::class, 'table' => 'sales_documents', 'types' => ['FV', 'ND', 'SI'], 'role' => 'is_customer', 'module' => 'ventas', 'tab' => 'Clientes', 'url' => '/ventas/clientes', 'party' => 'Cliente', 'total' => 'Total cartera', 'create' => '/terceros/crear?tipo=cliente', 'create_label' => 'Crear cliente'],
        'proveedores' => ['model' => PurchaseDocument::class, 'table' => 'purchase_documents', 'types' => ['FC', 'DS', 'ND', 'SI'], 'role' => 'is_supplier', 'module' => 'compras', 'tab' => 'Proveedores', 'url' => '/compras/proveedores', 'party' => 'Proveedor', 'total' => 'Total saldo', 'create' => '/terceros/crear?tipo=proveedor', 'create_label' => 'Crear proveedor'],
    ];

    public function customers(Request $request): Response
    {
        return $this->index($request, 'clientes');
    }

    public function suppliers(Request $request): Response
    {
        return $this->index($request, 'proveedores');
    }

    public function customerStatement(Request $request, Contact $contact): Response
    {
        return $this->statement($request, 'clientes', $contact);
    }

    public function supplierStatement(Request $request, Contact $contact): Response
    {
        return $this->statement($request, 'proveedores', $contact);
    }

    private function index(Request $request, string $side): Response
    {
        $cfg = self::SIDES[$side];
        $today = now()->toDateString();
        $balances = DB::table($cfg['table'])
            ->where('company_id', $request->user()->company_id)
            ->whereIn('type', $cfg['types'])
            ->where('status', '!=', 'annulled')
            ->where('balance', '>', 0)
            ->groupBy('contact_id')
            ->selectRaw('contact_id, SUM(CASE WHEN due_date <= ? THEN balance ELSE 0 END) AS overdue, SUM(CASE WHEN due_date IS NULL OR due_date > ? THEN balance ELSE 0 END) AS current_due', [$today, $today]);

        $estado = $request->input('estado', 'activo');
        $saldo = $request->input('saldo', 'todos');

        $rows = Contact::query()
            ->leftJoinSub($balances, 'b', 'b.contact_id', '=', 'contacts.id')
            ->where(fn ($q) => $q->where("contacts.{$cfg['role']}", true)->orWhereNotNull('b.contact_id'))
            ->when($estado !== 'todos', fn ($q) => $q->where('contacts.active', $estado === 'activo'))
            ->when($saldo === 'con_saldo', fn ($q) => $q->whereNotNull('b.contact_id'))
            ->when($saldo === 'vencido', fn ($q) => $q->where('b.overdue', '>', 0))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('contacts.name', 'like', "%$t%")->orWhere('contacts.identification', 'like', "$t%")))
            ->orderByRaw('b.contact_id IS NULL')
            ->orderBy('contacts.name')
            ->select('contacts.id', 'contacts.name', 'contacts.identification', 'contacts.branch_office', 'contacts.active', DB::raw('COALESCE(b.overdue,0) AS overdue'), DB::raw('COALESCE(b.current_due,0) AS current_due'))
            ->paginate($request->integer('per_page', 50))
            ->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'identification' => $c->identification,
                'branch' => $c->branch_office ? (string) $c->branch_office : '-',
                'overdue' => (float) $c->overdue,
                'current' => (float) $c->current_due,
                'credit' => 0.0,
                'total' => (float) $c->overdue + (float) $c->current_due,
                'active' => (bool) $c->active,
            ]);

        return Inertia::render('Portfolio/Index', [
            'side' => $side,
            'cfg' => collect($cfg)->only(['party', 'total', 'url', 'create', 'create_label', 'tab'])->all(),
            'rows' => $rows,
            'tabs' => DashboardController::tabs($cfg['module']),
            'filters' => ['q' => $request->input('q', ''), 'estado' => $estado, 'saldo' => $saldo],
        ]);
    }

    private function statement(Request $request, string $side, Contact $contact): Response
    {
        $cfg = self::SIDES[$side];
        $docs = $cfg['model']::query()
            ->where('contact_id', $contact->id)
            ->whereIn('type', $cfg['types'])
            ->where('status', '!=', 'annulled')
            ->when($request->input('todos') !== '1', fn ($q) => $q->where('balance', '>', 0))
            ->orderBy('date')->orderBy('number')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'supplier_doc' => $side === 'proveedores' && $d->supplier_number ? trim($d->supplier_prefix.'-'.$d->supplier_number, '-') : null,
                'date' => $d->date->translatedFormat('j M Y'),
                'due' => $d->due_date?->translatedFormat('j M Y'),
                'days' => $d->due_date && $d->due_date->isPast() && $d->balance > 0 ? (int) $d->due_date->diffInDays(now()) : 0,
                'total' => (float) $d->total,
                'balance' => (float) $d->balance,
            ]);

        return Inertia::render('Portfolio/Statement', [
            'side' => $side,
            'cfg' => collect($cfg)->only(['party', 'url', 'tab'])->all(),
            'contact' => ['id' => $contact->id, 'name' => $contact->name, 'identification' => $contact->identificationLabel(), 'email' => $contact->email, 'phone' => trim(($contact->phone_indicative ?? '').' '.($contact->phone ?? ''))],
            'docs' => $docs,
            'todos' => $request->input('todos') === '1',
            'tabs' => DashboardController::tabs($cfg['module']),
        ]);
    }
}
