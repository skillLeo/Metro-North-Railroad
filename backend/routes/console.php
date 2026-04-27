<?php

use App\Services\MetroNorthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('metro-north:refresh', function () {
    app(MetroNorthService::class)->refreshCache();
    $this->info('Metro North cache refreshed at ' . now()->toTimeString());
})->purpose('Refresh the Metro North board cache from MTA feeds');

Artisan::command('metro-north:build-schedule', function () {
    $this->info('Downloading GTFS static data (this takes ~30 seconds)...');
    $service = app(MetroNorthService::class);
    $result = $service->buildStratfordScheduleCache();
    $this->info('Built schedule lookup: ' . count($result) . ' Stratford departures cached.');
    $service->refreshCache();
    $this->info('Board cache refreshed with new schedule data.');
})->purpose('Build the Stratford departure schedule cache from static GTFS');

// Refresh live feed every 30 seconds
Schedule::command('metro-north:refresh')->everyThirtySeconds();

// Rebuild static schedule once per day (trains don't change daily)
Schedule::command('metro-north:build-schedule')->dailyAt('04:00');
