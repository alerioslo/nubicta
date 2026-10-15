<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('invoices:recurring')->dailyAt('06:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('collections:run')->dailyAt('07:00')->withoutOverlapping();
if (config('services.siigo.auto_sync')) {
    \Illuminate\Support\Facades\Schedule::command('siigo:sync')->hourlyAt(15)->between('06:00', '22:00')->withoutOverlapping()->runInBackground();
    \Illuminate\Support\Facades\Schedule::command('siigo:sync --dias=7 --completo')->dailyAt('02:30')->withoutOverlapping()->runInBackground();
}
