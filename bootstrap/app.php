<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class, \App\Http\Middleware\LogoutAfterRestore::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (QueryException $e, Request $request) {
            if (config('app.debug')) {
                return null;
            }
            $message = 'No se pudo completar la operación. Revisa la configuración de la empresa.';
            if ($request->header('X-Inertia')) {
                return back()->with('error', $message);
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $message], 500);
            }

            return response($message, 500);
        });
    })->create();
