<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Runs the database checks against the real PostgreSQL test database instead
 * of a mocked DB facade.
 */
class HealthCheckDatabaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_and_pgvector_are_ok_against_the_migrated_test_database(): void
    {
        Redis::shouldReceive('connection->ping')->andReturn(true);

        $response = $this->getJson(route('api.health'));

        $response->assertOk()
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.pgvector', 'ok');
    }
}
