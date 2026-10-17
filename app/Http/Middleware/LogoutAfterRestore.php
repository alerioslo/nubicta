<?php

namespace App\Http\Middleware;

use App\Services\Backup\BackupImporter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Despues de restaurar un respaldo los usuarios cambian: cierra las sesiones abiertas antes de la restauracion. */
class LogoutAfterRestore
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->hasSession()) {
            $epoch = (int) Cache::get(BackupImporter::epochKey(), 0);
            $seen = $request->session()->get('restore_epoch');
            if ($seen === null || $seen > $epoch) {
                $request->session()->put('restore_epoch', $epoch);
            } elseif ($seen < $epoch) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')->with('error', 'Se restauró una copia de seguridad. Vuelve a ingresar.');
            }
        }

        return $next($request);
    }
}
