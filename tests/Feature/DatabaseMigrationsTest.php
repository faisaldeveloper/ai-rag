<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseMigrationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_postgres_migrations_enable_pgvector_and_create_vector_columns(): void
    {
        $extension = DB::selectOne('select extname from pg_extension where extname = ?', ['vector']);

        $this->assertNotNull($extension);
        $this->assertSame('vector', Schema::getColumnType('document_chunks', 'embedding'));
        $this->assertFalse(Schema::hasTable('poc_articles'));
    }

    public function test_pgvector_migrations_are_skipped_on_connections_other_than_postgres(): void
    {
        config(['database.connections.sqlite_guard' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->artisan('migrate', ['--database' => 'sqlite_guard'])->assertSuccessful();

        $this->assertTrue(Schema::connection('sqlite_guard')->hasTable('users'));
        $this->assertTrue(Schema::connection('sqlite_guard')->hasTable('documents'));
        $this->assertFalse(Schema::connection('sqlite_guard')->hasTable('document_chunks'));
    }
}
