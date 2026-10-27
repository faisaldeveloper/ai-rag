<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\ReembedDocument;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\DocumentIngestionService;
use App\Services\RAG\Retriever;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReembedDocumentTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config([
            'rag.embedding_provider' => 'gemini',
            'rag.embedding_model' => 'test-embedding-model',
            'rag.embedding_task_types' => true,
            'rag.chunk_size' => 20,
            'rag.chunk_overlap' => 5,
        ]);
    }

    public function test_a_completed_document_is_re_embedded_and_stays_completed(): void
    {
        Embeddings::fake();
        $document = $this->completedDocument("# Refunds\n\n".str_repeat('Refunds are issued within thirty days. ', 10));
        $old = DocumentChunk::factory()->for($document)->create(['chunk_index' => 0, 'content' => 'Old chunk']);

        ReembedDocument::dispatch($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertTrue($document->embedding_task_types);
        $this->assertSame('test-embedding-model', $document->embedding_model);
        $this->assertModelMissing($old);
        $this->assertGreaterThan(1, $document->chunks()->count());
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->providerOptions === ['taskType' => 'RETRIEVAL_DOCUMENT']);
    }

    public function test_the_old_chunks_stay_searchable_while_the_new_vectors_are_generated(): void
    {
        $vector = array_pad([1.0], config('rag.embedding_dimensions'), 0.0);
        $document = $this->completedDocument('New text.');
        $old = DocumentChunk::factory()->for($document)->create(['chunk_index' => 0, 'content' => 'Old chunk', 'embedding' => $vector]);
        $user = User::factory()->create();
        $foundDuringEmbedding = null;
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($user, $vector, &$foundDuringEmbedding): array {
            $foundDuringEmbedding = app(Retriever::class)->searchByVector($user, $vector)->pluck('chunkId')->all();

            return array_map(fn (): array => $vector, $prompt->inputs);
        });

        ReembedDocument::dispatch($document);

        $this->assertSame([$old->id], $foundDuringEmbedding);
        $this->assertSame(['New text.'], $document->chunks()->pluck('content')->all());
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function providerFailures(): array
    {
        return [
            'provider error' => [new AiException('Provider said: secret details')],
            'rate limit on the last attempt' => [RateLimitedException::forProvider('gemini', 429)],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_a_failed_re_embed_keeps_the_old_chunks_metadata_and_status(\Throwable $failure): void
    {
        Embeddings::fake(fn () => throw $failure);
        Log::spy();
        $document = $this->completedDocument('New text.');
        $old = DocumentChunk::factory()->for($document)->create(['chunk_index' => 0, 'content' => 'Old chunk']);
        $job = (new ReembedDocument($document))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        try {
            $job->handle(app(DocumentIngestionService::class));
            $this->fail('Expected the provider exception to be rethrown.');
        } catch (AiException $exception) {
            $job->failed($exception);
        }

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertFalse($document->embedding_task_types);
        $this->assertNull($document->error_message);
        $this->assertSame([$old->id], $document->chunks()->pluck('id')->all());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['document_id'] === $document->id
                && ! str_contains(json_encode($context), 'secret details')
                && ! str_contains(json_encode($context), 'New text.'),
        );
    }

    public function test_a_rate_limit_releases_the_job_with_backoff(): void
    {
        Embeddings::fake(fn () => throw RateLimitedException::forProvider('gemini', 429));
        $document = $this->completedDocument('New text.');
        $job = (new ReembedDocument($document))->withFakeQueueInteractions();
        $job->job->attempts = 2;

        $job->handle(app(DocumentIngestionService::class));

        $job->assertReleased(delay: 120);
        $job->assertNotFailed();
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notCompletedStates(): array
    {
        return [
            'pending' => ['pending'],
            'processing' => ['processing'],
            'failed' => ['failed'],
        ];
    }

    #[DataProvider('notCompletedStates')]
    public function test_documents_that_are_not_completed_are_left_alone(string $state): void
    {
        Embeddings::fake();
        $document = Document::factory()->create(['status' => DocumentStatus::from($state)]);
        $chunk = DocumentChunk::factory()->for($document)->create();

        ReembedDocument::dispatch($document);

        Embeddings::assertNothingGenerated();
        $this->assertSame($state, $document->fresh()->status->value);
        $this->assertModelExists($chunk);
    }

    public function test_the_status_machine_still_forbids_leaving_completed(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            $this->assertFalse(DocumentStatus::Completed->canTransitionTo($status));
        }
    }

    public function test_only_one_re_embed_per_document_is_queued(): void
    {
        $document = Document::factory()->completed()->create();

        $this->assertInstanceOf(ShouldBeUnique::class, new ReembedDocument($document));
        $this->assertSame((string) $document->id, (new ReembedDocument($document))->uniqueId());
    }

    /**
     * Store the contents on the documents disk and create a completed document embedded without task types.
     */
    private function completedDocument(string $contents): Document
    {
        Storage::disk('documents')->put('stored.md', $contents);

        return Document::factory()->completed()->create(['storage_path' => 'stored.md', 'mime_type' => 'text/markdown']);
    }
}
