<?php

use App\Jobs\SyncPluginRegistrations;
use App\Models\PluginConnection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull new and changed registrations from the plugin. Runs only when enabled in Configuración › Plugin de inscripciones.
Schedule::job(new SyncPluginRegistrations)
    ->everyTenMinutes()
    ->when(fn () => PluginConnection::query()->where('enabled', true)->exists())
    ->withoutOverlapping();

// Start scheduled campaigns and send the next batch, paced by the broadcast mailbox's hourly and daily limits.
Schedule::command('campaigns:send')->everyMinute()->withoutOverlapping();

// Preferred products (from purchases) and stars (from campaign opens and clicks) of every contact.
Schedule::command('contacts:refresh-insights')->hourly()->withoutOverlapping();
