<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Recaudos (RC, pestana de Ventas) y Pagos (RP, pestana de Compras). */
class ReceiptController extends Controller
{
    public function cashReceipts(Request $request): Response
    {
        return $this->index($request, 'RC');
    }

    public function paymentReceipts(Request $request): Response
    {
        return $this->index($request, 'RP');
    }

    private function index(Request $request, string $type): Response
    {
        $isRc = $type === 'RC';
        $receipts = Receipt::query()
            ->where('type', $type)
            ->with(['contact:id,name,identification', 'items:id,receipt_id,due_prefix,due_number,value'])
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%$t%")
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%"))))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(fn (Receipt $r) => [
                'id' => $r->id,
                'date' => $r->date->translatedFormat('j M Y'),
                'name' => $r->name,
                'contact' => $r->contact?->name,
                'identification' => $r->contact?->identification,
                'payment' => $r->payment_name,
                'applies_to' => $r->items->map(fn ($i) => $i->due_prefix ? "{$i->due_prefix}-{$i->due_number}" : null)->filter()->unique()->implode(', '),
                'total' => (float) $r->total,
                'annulled' => $r->status === 'annulled',
            ]);

        return Inertia::render('Receipts/Index', [
            'receipts' => $receipts,
            'type' => $type,
            'title' => $isRc ? 'Ventas' : 'Compras y gastos',
            'tabs' => DashboardController::tabs($isRc ? 'ventas' : 'compras'),
            'active' => $isRc ? 'Recaudos' : 'Documentos de compras y gastos',
            'url' => $isRc ? '/ventas/recaudos' : '/compras/pagos',
            'filters' => $request->only('q', 'desde', 'hasta'),
        ]);
    }
}
