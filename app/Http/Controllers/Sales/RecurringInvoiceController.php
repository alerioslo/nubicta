<?php

namespace App\Http\Controllers\Sales;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use App\Services\Documents\RecurringInvoiceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Facturas recurrentes: a partir de una factura modelo se elabora una nueva cada periodo (comando invoices:recurring). */
class RecurringInvoiceController extends Controller
{
    private const COLUMNS = ['Factura recurrente', 'Identificación', 'Sucursal', 'Cliente', 'Recurrencia', 'Número de veces', 'Fecha inicio', 'Activo', 'Acción'];

    public function index(Request $request): Response
    {
        $rows = $this->query($request)->paginate(20)->withQueryString()->through(fn (RecurringInvoice $r) => [
            ['text' => $r->template?->name, 'href' => "/documentos/ventas/{$r->sales_document_id}"],
            $r->contact?->identification, $r->contact?->branch_office ?: '-', ['text' => $r->contact?->name, 'truncate' => true],
            $r->frequency, ($r->times ? "{$r->generated} de {$r->times}" : "{$r->generated} (indefinido)"), $r->start_date->format('d/m/Y'),
            ['text' => $r->active ? 'Sí' : 'No', 'badge' => $r->active ? 'ok' : 'off'],
            ['actions' => array_values(array_filter([
                ['text' => 'Editar', 'href' => "/ventas/facturas-recurrentes/{$r->id}/editar"],
                ['text' => $r->active ? 'Inactivar' : 'Activar', 'post' => "/ventas/facturas-recurrentes/{$r->id}/estado"],
                $r->active ? ['text' => 'Generar ahora', 'post' => "/ventas/facturas-recurrentes/{$r->id}/generar", 'confirm' => 'Se elaborará la siguiente factura de esta recurrencia con fecha de hoy. ¿Continuar?'] : null,
                ['text' => 'Eliminar', 'post' => "/ventas/facturas-recurrentes/{$r->id}/eliminar", 'confirm' => '¿Eliminar la recurrencia? Las facturas ya elaboradas no se modifican.', 'danger' => true],
            ]))],
        ]);

        return Inertia::render('GenericList', [
            'view' => [
                'title' => 'Ventas', 'tab' => 'Facturas recurrentes', 'url' => '/ventas/facturas-recurrentes', 'search_label' => 'Recurrencia', 'search' => 'Buscar por cliente o factura',
                'info' => 'Cada día a las 6:00 a. m. se elaboran automáticamente las facturas cuya próxima fecha ya llegó, copiando la factura modelo.',
                'buttons' => [
                    ['label' => 'Descargar', 'icon' => 'download', 'style' => 'outline', 'download' => '/ventas/facturas-recurrentes/excel'],
                    ['label' => 'Nueva factura recurrente', 'href' => '/ventas/facturas-recurrentes/crear'],
                ],
                'columns' => self::COLUMNS,
                'empty' => ['Aún no tienes facturas recurrentes', 'Crea una factura recurrente a partir de una factura de venta'],
            ],
            'rows' => $rows,
            'tabs' => DashboardController::tabs('ventas', 'Facturas recurrentes'),
            'filters' => $request->only('q'),
        ]);
    }

    public function excel(Request $request)
    {
        $rows = $this->query($request)->get()->map(fn (RecurringInvoice $r) => [
            $r->template?->name, $r->contact?->identification, $r->contact?->branch_office, $r->contact?->name, $r->frequency,
            $r->times ?? 'Indefinido', $r->generated, $r->start_date->format('d/m/Y'), $r->next_date->format('d/m/Y'), $r->active ? 'Sí' : 'No', $r->last_document, $r->last_error,
        ]);

        return ArrayExport::download('facturas_recurrentes.xlsx', ['Factura recurrente', 'Identificación', 'Sucursal', 'Cliente', 'Recurrencia', 'Número de veces',
            'Generadas', 'Fecha inicio', 'Próxima fecha', 'Activo', 'Última factura', 'Último error'], $rows, 'Facturas recurrentes');
    }

    public function create(Request $request): Response
    {
        $doc = $request->input('factura') ? SalesDocument::query()->where('type', 'FV')->find($request->input('factura')) : null;

        return $this->form(new RecurringInvoice(['sales_document_id' => $doc?->id, 'frequency' => 'Mensual', 'start_date' => now()->addMonthNoOverflow()->startOfMonth()]), $doc);
    }

    public function edit(RecurringInvoice $recurring): Response
    {
        return $this->form($recurring, $recurring->template);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        RecurringInvoice::create($data + ['next_date' => $data['start_date'], 'created_by' => Auth::id()]);

        return redirect('/ventas/facturas-recurrentes')->with('success', 'Se creó la factura recurrente.');
    }

    public function update(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $data = $this->validated($request);
        if ($recurring->generated === 0) {
            $data['next_date'] = $data['start_date'];
        }
        $recurring->update($data);

        return redirect('/ventas/facturas-recurrentes')->with('success', 'Se actualizó la factura recurrente.');
    }

    public function toggle(RecurringInvoice $recurring): RedirectResponse
    {
        if (! $recurring->active && $recurring->finished()) {
            return back()->with('error', 'La recurrencia ya elaboró todas sus facturas. Aumenta el número de veces para activarla.');
        }
        $recurring->update(['active' => ! $recurring->active]);

        return back()->with('success', $recurring->active ? 'La recurrencia quedó activa.' : 'La recurrencia quedó inactiva.');
    }

    public function generate(RecurringInvoice $recurring, RecurringInvoiceGenerator $generator): RedirectResponse
    {
        try {
            $doc = $generator->generate($recurring, now()->toDateString());
        } catch (\Throwable $e) {
            report($e);
            $recurring->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);

            return back()->with('error', 'No se pudo elaborar la factura: '.$e->getMessage());
        }

        return redirect("/documentos/ventas/{$doc->id}")->with('success', "Se elaboró la factura {$doc->name}.");
    }

    public function destroy(RecurringInvoice $recurring): RedirectResponse
    {
        $recurring->delete();

        return back()->with('success', 'Se eliminó la factura recurrente.');
    }

    private function query(Request $request): Builder
    {
        return RecurringInvoice::query()->with(['template:id,name', 'contact:id,name,identification,branch_office'])
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%"))
                ->orWhereHas('template', fn ($d) => $d->where('name', 'like', "%$t%"))))
            ->orderByDesc('active')->orderBy('next_date');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'sales_document_id' => ['required', 'integer'],
            'frequency' => ['required', 'in:'.implode(',', array_keys(RecurringInvoice::FREQUENCIES))],
            'start_date' => ['required', 'date'],
            'times' => ['nullable', 'integer', 'min:1', 'max:600'],
        ], ['sales_document_id.required' => 'Selecciona la factura modelo', 'start_date.required' => 'La fecha de inicio es obligatoria']);
        $doc = SalesDocument::query()->where('type', 'FV')->where('status', '!=', 'annulled')->find($data['sales_document_id'])
            ?? throw \Illuminate\Validation\ValidationException::withMessages(['sales_document_id' => 'La factura modelo no existe o está anulada']);

        return $data + ['contact_id' => $doc->contact_id];
    }

    private function form(RecurringInvoice $r, ?SalesDocument $doc): Response
    {
        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => $r->exists ? 'Editar factura recurrente' : 'Nueva factura recurrente', 'back' => '/ventas/facturas-recurrentes',
                'action' => $r->exists ? "/ventas/facturas-recurrentes/{$r->id}" : '/ventas/facturas-recurrentes', 'method' => $r->exists ? 'put' : 'post',
                'info' => 'La factura modelo se copia tal cual (cliente, ítems, precios, impuestos y forma de pago) en cada fecha de la recurrencia.',
                'fields' => [
                    ['name' => 'sales_document_id', 'label' => 'Factura de venta modelo', 'type' => 'lookup', 'url' => '/api/buscar/documentos', 'required' => true, 'placeholder' => 'Buscar por número de factura'],
                    ['name' => 'frequency', 'label' => 'Recurrencia', 'type' => 'select', 'options' => array_keys(RecurringInvoice::FREQUENCIES), 'required' => true],
                    ['name' => 'start_date', 'label' => 'Fecha inicio', 'type' => 'date', 'required' => true, 'help' => 'Fecha de la primera factura'],
                    ['name' => 'times', 'label' => 'Número de veces', 'type' => 'number', 'help' => 'Vacío = indefinido'],
                ],
            ],
            'initial' => [
                'sales_document_id' => $doc?->id, 'sales_document_id_label' => $doc ? $doc->name.' - '.($doc->contact?->name ?? '') : '',
                'frequency' => $r->frequency, 'start_date' => $r->start_date?->toDateString(), 'times' => $r->times,
            ],
        ]);
    }
}
