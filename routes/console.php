<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Revisión diaria de la configuración de seguridad: el resultado queda en el registro de seguridad
// (storage/logs/security-*.log, evento "security.check"; es "warning" si hay alguna falla grave).
Schedule::command('security:check --log')->dailyAt('06:00');
