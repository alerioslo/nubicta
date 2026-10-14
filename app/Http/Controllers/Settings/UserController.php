<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->where('company_id', $request->user()->company_id)
            ->when($request->input('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%$term%")
                ->orWhere('email', 'like', "%$term%")
                ->orWhere('identification', 'like', "%$term%")))
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'identification' => $u->identification,
                'roles' => $u->roles->pluck('name'),
                'is_seller' => $u->is_seller,
                'active' => $u->active,
                'last_login_at' => $u->last_login_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Settings/Users/Index', [
            'users' => $users,
            'filters' => $request->only('q'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Users/Form', [
            'user' => null,
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = User::create([...$data, 'company_id' => $request->user()->company_id]);
        $user->syncRoles($data['roles']);

        return redirect('/configuracion/usuarios')->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $user): Response
    {
        $this->authorizeCompany($user);

        return Inertia::render('Settings/Users/Form', [
            'user' => [...$user->only('id', 'name', 'identification', 'email', 'phone', 'is_seller', 'active'), 'roles' => $user->getRoleNames()],
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeCompany($user);
        $data = $this->validated($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($user->is($request->user())) {
            $data['active'] = true;
        }
        $user->update($data);
        $user->syncRoles($data['roles']);

        return redirect('/configuracion/usuarios')->with('success', 'Usuario actualizado correctamente.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'identification' => ['nullable', 'string', 'max:20'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_seller' => ['boolean'],
            'active' => ['boolean'],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,name'],
        ], [], ['name' => 'nombre', 'email' => 'correo electrónico', 'password' => 'contraseña', 'roles' => 'perfil']);
    }

    private function authorizeCompany(User $user): void
    {
        abort_unless($user->company_id === request()->user()->company_id, 404);
    }
}
