<?php

namespace App\Providers;

use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::looping(fn () => HealthCheckService::recordWorkerHeartbeat());
    }
}
