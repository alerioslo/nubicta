<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\DianInboxDocument;
use App\Models\DianInboxSync;
use App\Models\PurchaseDocument;
use App\Services\Documents\DocumentLocator;
use App\Services\Purchases\PurchaseInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bandeja DIAN > Causacion automatica DIAN: facturas recibidas de proveedores pendientes de causar. */
class DianInboxController extends Controller
{
    public const STATUSES = ['Pendiente' => 'Pendiente por causar', 'Causada' => 'Causada'];

    public function index(Request $request): Response
    {
        $docs = DianInboxDocument::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('supplier_name', 'like', "%$t%")->orWhere('supplier_nit', 'like', "$t%")->orWhere('cufe', 'like', "$t%")->orWhere('number', 'like', "%$t%")))
            ->when($request->input('estado'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 10))->withQueryString();
        $purchases = PurchaseDocument::query()->whereIn('id', $docs->pluck('purchase_document_id')->filter())->get()->keyBy('id');
        $syncs = DianInboxSync::query()->latest('id')->limit(50)->get();

        return Inertia::render('Purchases/DianInbox', [
            'docs' => $docs->through(fn (DianInboxDocument $d) => [
                'id' => $d->id, 'date' => $d->issue_date?->translatedFormat('j M Y'), 'number' => $d->number, 'kind' => $d->kind,
                'supplier' => $d->supplier_name, 'nit' => $d->supplier_nit, 'total' => (float) $d->total,
                'status' => self::STATUSES[$d->status] ?? $d->status, 'causada' => $d->status === 'Causada',
                'purchase' => ($p = $purchases[$d->purchase_document_id] ?? null) ? ['name' => $p->name, 'url' => DocumentLocator::url($p)] : null,
                'cufe' => $d->cufe,
            ]),
            'filters' => $request->only('q', 'estado', 'desde', 'hasta'),
            'statuses' => self::STATUSES,
            'syncs' => $syncs->map(fn (DianInboxSync $s) => [
                'date' => $s->created_at->setTimezone('America/Bogota')->format('d/m/Y h:i a'), 'status' => $s->status,
                'received' => $s->received, 'observations' => $s->observations,
            ]),
            'lastSync' => $syncs->first() ? ['status' => $s = $syncs->first()->status, 'date' => $syncs->first()->created_at->setTimezone('America/Bogota')->format('d/m/Y h:i a')] : null,
        ]);
    }

    public function sync(PurchaseInbox $inbox): RedirectResponse
    {
        $log = $inbox->sync();

        return $log->status === 'Exitosa'
            ? back()->with('success', $log->received ? "Se recibieron {$log->received} documentos nuevos." : 'No hay documentos nuevos en el buzón de recepción.')
            : back()->with('error', 'Sincronización fallida: '.$log->observations);
    }

    public function xml(int $id): StreamedResponse
    {
        $doc = DianInboxDocument::query()->findOrFail($id);

        return Storage::disk('local')->download($doc->xml_path, "{$doc->number}.xml");
    }
}
