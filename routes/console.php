<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincroniza las tasas del día (BCV, paralelo/USDT, EUR) automáticamente.
// Requiere tener corriendo el scheduler: `php artisan schedule:work`.
Schedule::command('rates:sync')->twiceDaily(8, 14)->withoutOverlapping();

// Recordatorio diario de rutinas del hogar pendientes (push).
Schedule::command('routines:remind')
    ->dailyAt(config('services.webpush.reminder_time', '20:00'))
    ->withoutOverlapping();
