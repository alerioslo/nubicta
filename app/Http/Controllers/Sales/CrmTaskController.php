<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Seguimiento comercial (CRM): tareas comerciales pendientes / terminadas por tercero. */
class CrmTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $done = $request->input('sub') === 'Terminadas';
        $rows = CrmTask::query()->with(['contact:id,name,identification', 'creator:id,name', 'assignee:id,name'])
            ->where('status', $done ? 'Terminada' : 'Pendiente')
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'like', "%$t%")->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%"))))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->input('categoria'), fn ($q, $c) => $q->where('category', $c))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id')
            ->paginate(20)->withQueryString()
            ->through(fn (CrmTask $t) => [
                ['text' => $t->status, 'badge' => $t->status === 'Terminada' ? 'ok' : ($t->due_date?->isPast() ? 'danger' : 'warn')],
                $t->creator?->name ?? '-', $t->created_at->format('d/m/Y'), $t->assignee?->name ?? '-',
                ['text' => $t->description.($t->contact ? " ({$t->contact->name})" : ''), 'truncate' => true],
                $t->due_date?->format('d/m/Y') ?? '-', $t->stage ?? '-', $t->origin,
                ['actions' => array_values(array_filter([
                    ['text' => 'Editar', 'href' => "/ventas/seguimiento-comercial/{$t->id}/editar"],
                    $t->status === 'Pendiente' ? ['text' => 'Terminar', 'post' => "/ventas/seguimiento-comercial/{$t->id}/terminar"] : ['text' => 'Reabrir', 'post' => "/ventas/seguimiento-comercial/{$t->id}/terminar"],
                    ['text' => 'Eliminar', 'post' => "/ventas/seguimiento-comercial/{$t->id}/eliminar", 'confirm' => '¿Eliminar la tarea comercial?', 'danger' => true],
                ]))],
            ]);

        return Inertia::render('GenericList', [
            'view' => [
                'title' => 'Ventas', 'tab' => 'Seguimiento comercial', 'heading' => 'Seguimiento comercial', 'url' => '/ventas/seguimiento-comercial',
                'subtabs' => ['Pendientes', 'Terminadas'], 'search' => 'Descripción o cliente', 'dates' => true,
                'selects' => [['name' => 'categoria', 'label' => 'Categoría', 'options' => CrmTask::CATEGORIES]],
                'buttons' => [['label' => 'Nueva tarea comercial', 'href' => '/ventas/seguimiento-comercial/crear']],
                'columns' => ['Estado tarea', 'Creado por', 'Fecha creación', 'Usuario responsable', 'Descripción', 'Fecha compromiso', 'Etapa', 'Origen', 'Acciones'],
                'empty' => ['Aún no tienes tareas comerciales', 'Crea una tarea para hacer seguimiento a tus clientes'],
            ],
            'rows' => $rows,
            'tabs' => DashboardController::tabs('ventas', 'Seguimiento comercial'),
            'filters' => $request->only('q', 'desde', 'hasta', 'sub', 'categoria'),
        ]);
    }

    public function create(): Response
    {
        return $this->form(new CrmTask(['assigned_to' => Auth::id(), 'category' => '2 - Comercial', 'origin' => 'Manual']));
    }

    public function edit(CrmTask $task): Response
    {
        return $this->form($task->load('contact:id,name,identification'));
    }

    public function store(Request $request): RedirectResponse
    {
        CrmTask::create($this->validated($request) + ['created_by' => Auth::id(), 'origin' => 'Manual', 'status' => 'Pendiente']);

        return redirect('/ventas/seguimiento-comercial')->with('success', 'Se creó la tarea comercial.');
    }

    public function update(Request $request, CrmTask $task): RedirectResponse
    {
        $task->update($this->validated($request));

        return redirect('/ventas/seguimiento-comercial')->with('success', 'Se actualizó la tarea comercial.');
    }

    public function toggle(CrmTask $task): RedirectResponse
    {
        $done = $task->status === 'Pendiente';
        $task->update(['status' => $done ? 'Terminada' : 'Pendiente', 'completed_at' => $done ? now() : null]);

        return back()->with('success', $done ? 'La tarea quedó terminada.' : 'La tarea quedó pendiente.');
    }

    public function destroy(CrmTask $task): RedirectResponse
    {
        $task->delete();

        return back()->with('success', 'Se eliminó la tarea comercial.');
    }

    private function validated(Request $request): array
    {
        $users = User::query()->where('company_id', CurrentCompany::id())->pluck('id')->all();

        return $request->validate([
            'category' => ['required', 'in:'.implode(',', CrmTask::CATEGORIES)],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'assigned_to' => ['required', 'in:'.implode(',', $users)],
            'description' => ['required', 'string', 'max:500'],
            'due_date' => ['nullable', 'date'],
            'stage' => ['nullable', 'string', 'max:60'],
            'commitment' => ['nullable', 'string', 'max:255'],
            'expected_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['description.required' => 'La descripción es obligatoria', 'assigned_to.required' => 'Selecciona el usuario responsable']);
    }

    private function form(CrmTask $task): Response
    {
        $users = User::query()->where('company_id', CurrentCompany::id())->orderBy('name')->get(['id', 'name']);

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => $task->exists ? 'Editar tarea comercial' : 'Nueva tarea comercial', 'back' => '/ventas/seguimiento-comercial',
                'action' => $task->exists ? "/ventas/seguimiento-comercial/{$task->id}" : '/ventas/seguimiento-comercial', 'method' => $task->exists ? 'put' : 'post',
                'subtitle' => $task->exists ? "Origen: {$task->origin} · Estado: {$task->status}" : null,
                'fields' => [
                    ['name' => 'category', 'label' => 'Categoría de la tarea', 'type' => 'select', 'options' => CrmTask::CATEGORIES, 'required' => true],
                    ['name' => 'contact_id', 'label' => 'Cliente / tercero', 'type' => 'lookup', 'url' => '/api/buscar/terceros'],
                    ['name' => 'assigned_to', 'label' => 'Usuario responsable', 'type' => 'select', 'options' => $users->map(fn ($u) => [$u->id, $u->name])->all(), 'required' => true],
                    ['name' => 'description', 'label' => 'Descripción', 'type' => 'textarea', 'required' => true, 'span' => 'full'],
                    ['name' => 'due_date', 'label' => 'Fecha compromiso', 'type' => 'date'],
                    ['name' => 'stage', 'label' => 'Etapa'],
                    ['name' => 'expected_value', 'label' => 'Valor esperado', 'type' => 'money'],
                    ['name' => 'commitment', 'label' => 'Compromiso', 'span' => 'full'],
                    ['name' => 'notes', 'label' => 'Observaciones / avance de la negociación', 'type' => 'textarea', 'span' => 'full'],
                ],
            ],
            'initial' => [
                'category' => $task->category, 'contact_id' => $task->contact_id,
                'contact_id_label' => $task->contact ? "{$task->contact->identification} - {$task->contact->name}" : '',
                'assigned_to' => $task->assigned_to, 'description' => $task->description, 'due_date' => $task->due_date?->toDateString(),
                'stage' => $task->stage, 'commitment' => $task->commitment, 'expected_value' => $task->expected_value, 'notes' => $task->notes,
            ],
        ]);
    }
}
