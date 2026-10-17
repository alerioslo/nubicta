<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('invoices:recurring')->dailyAt('06:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('collections:run')->dailyAt('07:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('dian:inbox-sync')->everyThirtyMinutes()->between('06:00', '22:00')->withoutOverlapping(20)->runInBackground();
if (config('services.siigo.auto_sync')) {
    \Illuminate\Support\Facades\Schedule::command('siigo:sync')->hourlyAt(15)->between('06:00', '22:00')->withoutOverlapping()->runInBackground();
    \Illuminate\Support\Facades\Schedule::command('siigo:sync --dias=7 --completo')->dailyAt('02:30')->withoutOverlapping()->runInBackground();
    \Illuminate\Support\Facades\Schedule::command('inventory:history')->hourlyAt(50)->between('06:00', '22:00')->withoutOverlapping()->runInBackground();
}
\Illuminate\Support\Facades\Schedule::command('inventory:history')->dailyAt('03:45')->withoutOverlapping()->runInBackground();
\Illuminate\Support\Facades\Schedule::command('siigo:orden-lineas --limite=1500')->hourlyAt(35)->withoutOverlapping()->runInBackground();
