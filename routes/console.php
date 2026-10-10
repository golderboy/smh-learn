<?php

declare(strict_types=1);

use App\Models\MasterImport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('reports:purge')->hourly()->withoutOverlapping();
Schedule::call(function (): void {
    MasterImport::whereNull('committed_at')->where('expires_at', '<', now())->delete();
})->name('master-imports:purge-expired')->hourly()->withoutOverlapping();

Schedule::call(function (): void {
    Cache::store('redis')->put('ops:scheduler-heartbeat', now()->timestamp, 180);
})->name('ops:scheduler-heartbeat')->everyMinute();

if (app()->environment('local')) {
    Schedule::call(function (): void {
        Cache::store('redis')->put('dev:scheduler-heartbeat', now()->timestamp, 120);
    })->name('dev:scheduler-heartbeat')->everyMinute();
}
