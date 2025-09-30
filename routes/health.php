<?php

use Illuminate\Support\Facades\Route;
use Mirzaaghazadeh\SmartFailover\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| SmartFailover Health Check Routes
|--------------------------------------------------------------------------
|
| These routes provide health check endpoints for monitoring the status
| of all configured failover services including database, cache, queue,
| storage, and mail services.
|
*/

$routePath = config('smart-failover.health_check.route_path', '/health/smart-failover');
$middleware = config('smart-failover.health_check.middleware', ['web']);

Route::middleware($middleware)
    ->name('smart-failover.health.')
    ->prefix($routePath)
    ->group(function () {
        // Main health check endpoint
        Route::get('/', [HealthController::class, 'index'])
            ->name('index');
        
        // Detailed health check with service breakdown
        Route::get('/detailed', [HealthController::class, 'detailed'])
            ->name('detailed');
        
        // Individual service health checks
        Route::get('/database', [HealthController::class, 'database'])
            ->name('database');
        
        Route::get('/cache', [HealthController::class, 'cache'])
            ->name('cache');
        
        Route::get('/queue', [HealthController::class, 'queue'])
            ->name('queue');
        
        Route::get('/storage', [HealthController::class, 'storage'])
            ->name('storage');
        
        Route::get('/mail', [HealthController::class, 'mail'])
            ->name('mail');
    });
