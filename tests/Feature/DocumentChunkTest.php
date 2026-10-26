<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class DocumentChunkTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_embedding_and_metadata_round_trip(): void
    {
        $embedding = Embeddings::fakeEmbedding(config('rag.embedding_dimensions'));

        $chunk = DocumentChunk::factory()->create([
            'embedding' => $embedding,
            'metadata' => ['page' => 3, 'heading' => 'Refunds'],
        ])->fresh();

        $this->assertCount(config('rag.embedding_dimensions'), $chunk->embedding);
        $this->assertEqualsWithDelta($embedding[0], $chunk->embedding[0], 1e-6);
        $this->assertSame(['page' => 3, 'heading' => 'Refunds'], $chunk->metadata);
    }

    public function test_chunk_belongs_to_its_document(): void
    {
        $document = Document::factory()->create();
        $chunk = DocumentChunk::factory()->for($document)->create();

        $this->assertTrue($chunk->document->is($document));
    }

    public function test_chunk_index_is_unique_within_a_document(): void
    {
        $chunk = DocumentChunk::factory()->create(['chunk_index' => 0]);

        $this->expectException(UniqueConstraintViolationException::class);

        DocumentChunk::factory()->for($chunk->document)->create(['chunk_index' => 0]);
    }

    public function test_the_same_chunk_index_may_be_used_by_different_documents(): void
    {
        DocumentChunk::factory()->create(['chunk_index' => 0]);
        DocumentChunk::factory()->create(['chunk_index' => 0]);

        $this->assertDatabaseCount('document_chunks', 2);
    }

    public function test_deleting_a_document_deletes_its_chunks(): void
    {
        $document = Document::factory()->create();
        DocumentChunk::factory()->for($document)->count(2)->create();
        $otherChunk = DocumentChunk::factory()->create();

        $document->delete();

        $this->assertDatabaseCount('document_chunks', 1);
        $this->assertModelExists($otherChunk);
    }
}
