<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Report the status of the database, pgvector, Redis, and the queue worker.
     *
     * Responds 503 when any check errors. A queue status of "unknown" (no worker
     * running) marks the app as degraded but still responds 200.
     */
    public function __invoke(HealthCheckService $healthCheck): JsonResponse
    {
        $checks = $healthCheck->check();

        $status = match (true) {
            in_array(HealthCheckService::Error, $checks, true) => 'error',
            in_array(HealthCheckService::Unknown, $checks, true) => 'degraded',
            default => 'ok',
        };

        return response()->json(
            ['status' => $status, 'checks' => $checks],
            $status === 'error' ? 503 : 200,
        );
    }
}
