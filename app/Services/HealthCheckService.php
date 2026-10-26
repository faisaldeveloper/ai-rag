<?php

namespace App\Services;

use App\Jobs\HealthPing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks the infrastructure the application depends on.
 *
 * Results are reduced to "ok", "error", or "unknown" so that no hostnames,
 * credentials, or driver error messages leak into the response.
 */
class HealthCheckService
{
    public const string Ok = 'ok';

    public const string Error = 'error';

    public const string Unknown = 'unknown';

    public const string WorkerHeartbeatCacheKey = 'health:queue-worker-heartbeat';

    /**
     * How long a worker heartbeat stays valid. Workers refresh it on every loop.
     */
    public const int WorkerHeartbeatTtlSeconds = 60;

    private const int QueuePollIntervalMilliseconds = 100;

    private const int QueueTimeoutMilliseconds = 5000;

    /**
     * Run every check.
     *
     * @return array{database: string, pgvector: string, redis: string, queue: string}
     */
    public function check(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'pgvector' => $this->checkPgvector(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];
    }

    public static function pingCacheKey(string $token): string
    {
        return "health:queue-ping:{$token}";
    }

    public static function recordWorkerHeartbeat(): void
    {
        Cache::put(self::WorkerHeartbeatCacheKey, now()->getTimestamp(), self::WorkerHeartbeatTtlSeconds);
    }

    public function checkDatabase(): string
    {
        return $this->attempt('database', function (): string {
            DB::select('select 1');

            return self::Ok;
        });
    }

    public function checkPgvector(): string
    {
        return $this->attempt('pgvector', function (): string {
            $extension = DB::selectOne('select extname from pg_extension where extname = ?', ['vector']);

            return $extension === null ? self::Error : self::Ok;
        });
    }

    public function checkRedis(): string
    {
        return $this->attempt('redis', function (): string {
            Redis::connection()->ping();

            return self::Ok;
        });
    }

    /**
     * Dispatch a HealthPing and wait for a worker to process it.
     *
     * Returns "unknown" without dispatching when no worker has recently sent a
     * heartbeat, so that pings do not pile up in a queue nobody is consuming.
     */
    public function checkQueue(): string
    {
        return $this->attempt('queue', function (): string {
            if (! Cache::has(self::WorkerHeartbeatCacheKey)) {
                return self::Unknown;
            }

            $token = (string) Str::uuid();
            $cacheKey = self::pingCacheKey($token);

            HealthPing::dispatch($token);

            $attempts = intdiv(self::QueueTimeoutMilliseconds, self::QueuePollIntervalMilliseconds);

            for ($attempt = 0; $attempt <= $attempts; $attempt++) {
                if (Cache::pull($cacheKey) !== null) {
                    return self::Ok;
                }

                if ($attempt < $attempts) {
                    Sleep::for(self::QueuePollIntervalMilliseconds)->milliseconds();
                }
            }

            return self::Error;
        });
    }

    /**
     * @param  callable(): string  $check
     */
    private function attempt(string $name, callable $check): string
    {
        try {
            return $check();
        } catch (Throwable $exception) {
            Log::warning("Health check [{$name}] failed.", ['exception' => $exception::class]);

            return self::Error;
        }
    }
}
