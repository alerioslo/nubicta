<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('invoices:recurring')->dailyAt('06:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('collections:run')->dailyAt('07:00')->withoutOverlapping();
