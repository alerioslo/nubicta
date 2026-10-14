<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** "¿Olvidaste tu contrasena?": enlace de restablecimiento por correo (vigente 60 minutos). */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/Password', ['mode' => 'request', 'status' => session('status')]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], ['email.required' => 'Ingresa tu correo electrónico']);
        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['email' => 'No se pudo enviar el correo. Revisa la configuración de correo del servidor o pide al administrador que restablezca tu contraseña.']);
        }
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'Ya enviamos un enlace hace poco. Espera un minuto e inténtalo de nuevo.']);
        }

        return back()->with('status', 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/Password', ['mode' => 'reset', 'token' => $token, 'email' => $request->input('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], ['password.confirmed' => 'Las contraseñas no coinciden', 'password.min' => 'La contraseña debe tener al menos 8 caracteres']);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'El enlace no es válido o ya venció. Solicita uno nuevo.']);
        }

        return redirect('/login')->with('success', 'Tu contraseña se actualizó. Ya puedes iniciar sesión.');
    }
}
