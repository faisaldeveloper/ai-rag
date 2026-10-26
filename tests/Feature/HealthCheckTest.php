<?php

namespace Tests\Feature;

use App\Jobs\HealthPing;
use App\Services\HealthCheckService;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_it_reports_ok_when_every_dependency_is_healthy(): void
    {
        $this->fakeHealthyDatabase();
        $this->fakeHealthyRedis();
        HealthCheckService::recordWorkerHeartbeat();

        $response = $this->getJson(route('api.health'));

        $response->assertOk()->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'database' => 'ok',
                'pgvector' => 'ok',
                'redis' => 'ok',
                'queue' => 'ok',
            ],
        ]);
    }

    public function test_queue_is_unknown_and_nothing_is_dispatched_when_no_worker_is_running(): void
    {
        Queue::fake();
        $this->fakeHealthyDatabase();
        $this->fakeHealthyRedis();

        $response = $this->getJson(route('api.health'));

        $response->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue', 'unknown');
        Queue::assertNothingPushed();
    }

    public function test_queue_errors_when_a_running_worker_does_not_process_the_ping_within_five_seconds(): void
    {
        Queue::fake();
        Sleep::fake();
        $this->fakeHealthyDatabase();
        $this->fakeHealthyRedis();
        HealthCheckService::recordWorkerHeartbeat();

        $response = $this->getJson(route('api.health'));

        $response->assertServiceUnavailable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('checks.queue', 'error');
        Queue::assertPushed(HealthPing::class, 1);
        Sleep::assertSleptTimes(50);
    }

    public function test_pgvector_errors_when_the_extension_is_not_installed(): void
    {
        DB::shouldReceive('select')->andReturn([(object) ['?column?' => 1]]);
        DB::shouldReceive('selectOne')->andReturnNull();
        $this->fakeHealthyRedis();
        HealthCheckService::recordWorkerHeartbeat();

        $response = $this->getJson(route('api.health'));

        $response->assertServiceUnavailable()
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.pgvector', 'error');
    }

    public function test_database_failure_does_not_leak_hostnames_or_credentials(): void
    {
        $failure = new RuntimeException('could not connect to db.internal.example as knowledge with password hunter2');
        DB::shouldReceive('select')->andThrow($failure);
        DB::shouldReceive('selectOne')->andThrow($failure);
        $this->fakeHealthyRedis();
        HealthCheckService::recordWorkerHeartbeat();

        $response = $this->getJson(route('api.health'));

        $response->assertServiceUnavailable()
            ->assertJsonPath('checks.database', 'error')
            ->assertJsonPath('checks.pgvector', 'error')
            ->assertDontSee('db.internal.example')
            ->assertDontSee('hunter2');
    }

    public function test_redis_errors_when_ping_fails(): void
    {
        $this->fakeHealthyDatabase();
        Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('Connection refused [tcp://10.0.0.5:6379]'));
        HealthCheckService::recordWorkerHeartbeat();

        $response = $this->getJson(route('api.health'));

        $response->assertServiceUnavailable()
            ->assertJsonPath('checks.redis', 'error')
            ->assertDontSee('10.0.0.5');
    }

    public function test_queue_worker_loop_records_a_heartbeat(): void
    {
        $this->assertFalse(Cache::has(HealthCheckService::WorkerHeartbeatCacheKey));

        event(new Looping('redis', 'default'));

        $this->assertTrue(Cache::has(HealthCheckService::WorkerHeartbeatCacheKey));
    }

    public function test_health_ping_job_marks_its_token_as_processed_and_is_idempotent(): void
    {
        $job = new HealthPing('test-token');

        $job->handle();
        $job->handle();

        $this->assertTrue(Cache::get(HealthCheckService::pingCacheKey('test-token')));
    }

    private function fakeHealthyDatabase(): void
    {
        DB::shouldReceive('select')->with('select 1')->andReturn([(object) ['?column?' => 1]]);
        DB::shouldReceive('selectOne')
            ->with('select extname from pg_extension where extname = ?', ['vector'])
            ->andReturn((object) ['extname' => 'vector']);
    }

    private function fakeHealthyRedis(): void
    {
        Redis::shouldReceive('connection->ping')->andReturn(true);
    }
}
