<?php

namespace Tests\Feature;

use App\Models\Document;
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

    public function test_documents_embedded_before_task_types_are_marked_as_embedded_without_them(): void
    {
        $migration = require database_path('migrations/2026_10_27_145209_add_embedding_task_types_to_documents_table.php');
        $migration->down();
        $embedded = Document::factory()->create(['embedding_provider' => 'gemini', 'embedding_model' => 'old-model']);
        $notEmbedded = Document::factory()->create();

        $migration->up();

        $this->assertFalse($embedded->fresh()->embedding_task_types);
        $this->assertNull($notEmbedded->fresh()->embedding_task_types);
    }
}
