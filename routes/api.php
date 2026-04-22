<?php

use App\Services\MetroNorthService;
use Illuminate\Support\Facades\Route;

Route::get('/board', function (MetroNorthService $service) {
    return response()->json($service->getBoard());
});

Route::get('/alerts', function (MetroNorthService $service) {
    return response()->json($service->getAlerts());
});
