<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Roles/Index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Roles/Form', ['role' => null, 'matrix' => $this->matrix()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect('/configuracion/perfiles')->with('success', 'Perfil creado correctamente.');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Settings/Roles/Form', [
            'role' => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')],
            'matrix' => $this->matrix(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'Administrador', 403, 'El perfil Administrador no se puede modificar.');
        $data = $this->validated($request, $role);
        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect('/configuracion/perfiles')->with('success', 'Perfil actualizado correctamente.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,name'],
        ], [], ['name' => 'nombre del perfil']);
    }

    /** @return list<array{key:string,label:string,actions:list<array{name:string,label:string}>}> */
    private function matrix(): array
    {
        $labels = config('erp.action_labels');

        return collect(config('erp.permissions'))->map(fn ($m, $key) => [
            'key' => $key,
            'label' => $m['label'],
            'actions' => array_map(fn ($a) => ['name' => "$key.$a", 'label' => $labels[$a] ?? $a], $m['actions']),
        ])->values()->all();
    }
}
