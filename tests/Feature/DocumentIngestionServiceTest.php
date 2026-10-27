<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RAG\DocumentIngestionService;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\IngestionException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class DocumentIngestionServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config([
            'rag.embedding_provider' => 'gemini',
            'rag.embedding_model' => 'test-embedding-model',
            'rag.chunk_size' => 20,
            'rag.chunk_overlap' => 5,
            'rag.embedding_batch_size' => 2,
        ]);
    }

    public function test_it_persists_embedded_chunks_and_records_the_embedding_model(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument("# Refunds\n\nRefunds are issued within 30 days.\n\n# Shipping\n\nOrders ship in two days.\n");

        $count = app(DocumentIngestionService::class)->ingest($document);

        $this->assertSame(2, $count);
        $chunks = $document->chunks()->get();
        $this->assertSame([0, 1], $chunks->pluck('chunk_index')->all());
        $this->assertSame("# Refunds\n\nRefunds are issued within 30 days.", $chunks[0]->content);
        $this->assertSame(['heading' => 'Refunds'], $chunks[0]->metadata);
        $this->assertSame(12, $chunks[0]->token_count);
        $this->assertCount(config('rag.embedding_dimensions'), $chunks[0]->embedding);
        $this->assertSame(['heading' => 'Shipping'], $chunks[1]->metadata);

        $document->refresh();
        $this->assertSame('gemini', $document->embedding_provider);
        $this->assertSame('test-embedding-model', $document->embedding_model);
        $this->assertSame(DocumentStatus::Processing, $document->status, 'The service must not change the status.');
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === [$chunks[0]->content, $chunks[1]->content]);
    }

    public function test_ingesting_again_replaces_the_existing_chunks(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument(str_repeat('Every sentence here adds a few more words. ', 20));
        DocumentChunk::factory()->for($document)->count(3)->sequence(
            ['chunk_index' => 0], ['chunk_index' => 50], ['chunk_index' => 99],
        )->create(['content' => 'Stale chunk']);

        $first = app(DocumentIngestionService::class)->ingest($document);
        $firstContents = $document->chunks()->pluck('content')->all();
        $second = app(DocumentIngestionService::class)->ingest($document);

        $this->assertGreaterThan(3, $first);
        $this->assertSame($first, $second);
        $this->assertSame(range(0, $second - 1), $document->chunks()->pluck('chunk_index')->all());
        $this->assertSame($firstContents, $document->chunks()->pluck('content')->all());
        $this->assertDatabaseMissing('document_chunks', ['content' => 'Stale chunk']);
    }

    public function test_wrong_embedding_dimensions_leave_existing_chunks_untouched(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(4)]]);
        $document = $this->storedDocument('Some text.');
        $existing = DocumentChunk::factory()->for($document)->create(['chunk_index' => 0]);

        try {
            app(DocumentIngestionService::class)->ingest($document);
            $this->fail('Expected an EmbeddingException.');
        } catch (EmbeddingException) {
            //
        }

        $this->assertSame([$existing->id], $document->chunks()->pluck('id')->all());
        $this->assertNull($document->fresh()->embedding_model);
    }

    public function test_extraction_errors_stop_before_calling_the_embedding_provider(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument("  \n\n ");

        try {
            app(DocumentIngestionService::class)->ingest($document);
            $this->fail('Expected an IngestionException.');
        } catch (IngestionException $exception) {
            $this->assertSame('The document contains no text.', $exception->getMessage());
        }

        Embeddings::assertNothingGenerated();
        $this->assertDatabaseEmpty('document_chunks');
    }

    /**
     * Store the contents on the documents disk and create a processing document pointing at them.
     */
    private function storedDocument(string $contents): Document
    {
        Storage::disk('documents')->put('stored.md', $contents);

        return Document::factory()->processing()->create(['storage_path' => 'stored.md', 'mime_type' => 'text/markdown']);
    }
}
