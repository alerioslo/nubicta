<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Services\Documents\Numbering::class);
        $this->app->scoped(\App\Services\Api\Webhooks::class);
    }

    public function boot(): void
    {
        Gate::before(fn ($user) => $user->hasRole('Administrador') ? true : null);
        \App\Services\Api\Webhooks::boot();
    }
}
