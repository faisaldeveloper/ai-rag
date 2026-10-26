<?php

namespace App\Jobs;

use App\Services\HealthCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Marker job dispatched by the health check to prove a queue worker is processing jobs.
 */
class HealthPing implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $token) {}

    /**
     * Mark the ping as processed. Safe to run more than once.
     */
    public function handle(): void
    {
        Cache::put(HealthCheckService::pingCacheKey($this->token), true, now()->addMinute());
    }
}
