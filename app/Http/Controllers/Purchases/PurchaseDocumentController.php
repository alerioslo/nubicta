<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\PurchaseDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseDocumentController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->input('tipo') === 'RP') {
            return redirect('/compras/pagos?'.http_build_query($request->except('tipo')));
        }

        $docs = PurchaseDocument::query()
            ->with('contact:id,name,identification,branch_office')
            ->when($request->input('tipo'), fn ($q, $t) => $q->where('type', $t), fn ($q) => $q->where('type', '!=', 'SI'))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%$t%")->orWhere('supplier_number', 'like', "%$t%")
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%"))))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->input('saldo') === 'pendiente', fn ($q) => $q->where('balance', '>', 0))
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(fn (PurchaseDocument $d) => [
                'id' => $d->id,
                'date' => $d->date->format('d/m/Y'),
                'name' => $d->name,
                'type' => PurchaseDocument::TYPES[$d->type] ?? $d->type,
                'transaction' => $d->type === 'DS' ? 'Documento soporte' : 'Compra/Gasto',
                'supplier_invoice' => trim(($d->supplier_prefix ?? '').'-'.($d->supplier_number ?? ''), '-'),
                'supplier' => $d->contact?->name,
                'contact_id' => $d->contact_id,
                'identification' => $d->contact?->identification,
                'branch' => $d->contact?->branch_office ?: '-',
                'dian_status' => $d->dian_status,
                'annulled' => $d->status === 'annulled',
                'due_date' => $d->due_date?->translatedFormat('j M Y'),
                'total' => (float) $d->total,
                'balance' => (float) $d->balance,
                'currency' => $d->currency,
            ]);

        return Inertia::render('Purchases/Index', [
            'documents' => $docs,
            'tabs' => DashboardController::tabs('compras'),
            'filters' => $request->only('q', 'desde', 'hasta', 'saldo', 'tipo'),
        ]);
    }
}
