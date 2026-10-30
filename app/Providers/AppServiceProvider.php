<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Services\Documents\Numbering::class);
        $this->app->scoped(\App\Services\Api\Webhooks::class);
    }

    public function boot(): void
    {   
        if (request()->header('x-forwarded-proto') == 'https') {
            URL::forceScheme('https');
        }
        Gate::before(fn ($user) => $user->hasRole('Administrador') ? true : null);
        \App\Services\Api\Webhooks::boot();
    }
}
