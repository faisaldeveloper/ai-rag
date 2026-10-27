<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RAG\DocumentIngestionService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ProcessDocumentTest extends TestCase
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
        ]);
    }

    public function test_a_pending_document_is_chunked_embedded_and_completed(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument("# Refunds\n\n".str_repeat('Refunds are issued within thirty days. ', 10));

        ProcessDocument::dispatch($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNull($document->error_message);
        $this->assertSame('test-embedding-model', $document->embedding_model);
        $this->assertGreaterThan(1, $document->chunks()->count());
        $this->assertSame(range(0, $document->chunks()->count() - 1), $document->chunks()->pluck('chunk_index')->all());
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function rateLimitExceptions(): array
    {
        return [
            'SDK rate limit exception' => [RateLimitedException::forProvider('gemini', 429)],
            'raw HTTP 429 response' => [new RequestException(new Response(new Psr7Response(429)))],
        ];
    }

    #[DataProvider('rateLimitExceptions')]
    public function test_a_rate_limit_releases_the_job_with_a_growing_delay_instead_of_failing(Throwable $rateLimit): void
    {
        Embeddings::fake(fn () => throw $rateLimit);
        $document = $this->storedDocument('Some text.');

        $job = (new ProcessDocument($document))->withFakeQueueInteractions();
        $job->handle(app(DocumentIngestionService::class));

        $job->assertReleased(delay: 60);
        $job->assertNotFailed();
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);

        $retry = (new ProcessDocument($document))->withFakeQueueInteractions();
        $retry->job->attempts = 3;
        $retry->handle(app(DocumentIngestionService::class));

        $retry->assertReleased(delay: 240);
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
    }

    public function test_a_rate_limit_on_the_last_attempt_fails_the_document_with_a_retry_hint(): void
    {
        Embeddings::fake(fn () => throw RateLimitedException::forProvider('gemini', 429));
        $document = $this->storedDocument('Some text.');
        $job = (new ProcessDocument($document))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        try {
            $job->handle(app(DocumentIngestionService::class));
            $this->fail('Expected the rate limit exception to be rethrown.');
        } catch (RateLimitedException $exception) {
            $job->failed($exception);
        }

        $job->assertNotReleased();
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'The embedding provider rate limit was reached. Retry the document later.',
        ]);
    }

    public function test_the_job_allows_ten_attempts_but_only_three_errors_with_increasing_backoff(): void
    {
        $job = new ProcessDocument(Document::factory()->create());

        $this->assertSame(10, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->countCrashesAsExceptions);
        $backoff = $job->backoff();
        $this->assertCount($job->maxExceptions, $backoff);
        $this->assertSame($backoff, array_values(array_unique($backoff)));
        $sorted = $backoff;
        sort($sorted);
        $this->assertSame($sorted, $backoff);
        $this->assertLessThan(config('queue.connections.redis.retry_after'), $job->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
    }

    public function test_the_rate_limit_delay_doubles_up_to_a_cap(): void
    {
        $delays = array_map(ProcessDocument::rateLimitDelay(...), range(1, 9));

        $this->assertSame([60, 120, 240, 480, 900, 900, 900, 900, 900], $delays);
    }

    public function test_the_unique_lock_outlives_the_longest_possible_retry_window(): void
    {
        $job = new ProcessDocument(Document::factory()->create());

        $longestWindow = $job->tries * $job->timeout
            + array_sum(array_map(ProcessDocument::rateLimitDelay(...), range(1, $job->tries - 1)));

        $this->assertGreaterThan($longestWindow, $job->uniqueFor);
    }

    public function test_an_extraction_failure_marks_the_document_failed_with_its_safe_message(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument("  \n\n ");

        $this->dispatchExpectingFailure($document);

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'failed', 'error_message' => 'The document contains no text.']);
        $this->assertDatabaseEmpty('document_chunks');
        Embeddings::assertNothingGenerated();
    }

    public function test_an_unexpected_failure_stores_a_generic_message_without_internal_details(): void
    {
        Embeddings::fake(fn () => throw new RuntimeException('POST https://provider.test/embed?key=sk-secret-key failed for /var/www/storage/app/documents/stored.md: Some text.'));
        $document = $this->storedDocument('Some text.');

        $this->dispatchExpectingFailure($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('Processing failed because of an unexpected error.', $document->error_message);
        $this->assertStringNotContainsString('sk-secret', $document->error_message);
        $this->assertStringNotContainsString('Some text', $document->error_message);
    }

    public function test_wrong_embedding_dimensions_fail_the_document_safely_without_storing_chunks(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(4)]]);
        $document = $this->storedDocument('Some text.');

        $this->dispatchExpectingFailure($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('The embedding provider returned an unexpected response.', $document->error_message);
        $this->assertNull($document->embedding_model);
        $this->assertDatabaseEmpty('document_chunks');
    }

    public function test_a_job_that_ran_out_of_attempts_fails_the_document(): void
    {
        $document = Document::factory()->processing()->create();

        (new ProcessDocument($document))->failed(new MaxAttemptsExceededException('ProcessDocument has been attempted too many times.'));

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'Processing took too long or was interrupted too many times.',
        ]);
    }

    public function test_a_job_that_failed_before_claiming_the_document_still_fails_it(): void
    {
        $document = Document::factory()->create();

        (new ProcessDocument($document))->failed(new RuntimeException('Database connection lost.'));

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'failed']);
    }

    public function test_processing_a_document_twice_does_not_duplicate_chunks(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument(str_repeat('Every sentence here adds a few more words. ', 10));

        ProcessDocument::dispatch($document);
        $chunkIds = $document->chunks()->pluck('id')->all();
        (new ProcessDocument($document))->handle(app(DocumentIngestionService::class));

        $this->assertGreaterThan(1, count($chunkIds));
        $this->assertSame($chunkIds, $document->chunks()->pluck('id')->all());
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function retriedAttempts(): array
    {
        return [
            'second attempt' => [2],
            'attempt after several rate limits' => [7],
            'last attempt' => [10],
        ];
    }

    #[DataProvider('retriedAttempts')]
    public function test_a_retried_attempt_resumes_a_document_left_processing_by_a_crashed_attempt(int $attempt): void
    {
        Embeddings::fake();
        $document = $this->storedDocument(str_repeat('Every sentence here adds a few more words. ', 10));
        $document->transitionTo(DocumentStatus::Processing);
        DocumentChunk::factory()->for($document)->count(2)->sequence(['chunk_index' => 0], ['chunk_index' => 7])->create(['content' => 'Left by the crashed attempt']);

        $job = (new ProcessDocument($document))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        $job->handle(app(DocumentIngestionService::class));

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
        $this->assertSame(range(0, $document->chunks()->count() - 1), $document->chunks()->pluck('chunk_index')->all());
        $this->assertDatabaseMissing('document_chunks', ['content' => 'Left by the crashed attempt']);
    }

    public function test_a_first_attempt_leaves_a_document_another_job_is_processing_alone(): void
    {
        Embeddings::fake();
        $document = $this->storedDocument('Some text.');
        $document->transitionTo(DocumentStatus::Processing);

        $job = (new ProcessDocument($document))->withFakeQueueInteractions();
        $job->handle(app(DocumentIngestionService::class));

        $job->assertNotFailed();
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
        Embeddings::assertNothingGenerated();
    }

    /**
     * @return array<string, array{DocumentStatus}>
     */
    public static function finishedStatuses(): array
    {
        return [
            'completed' => [DocumentStatus::Completed],
            'failed' => [DocumentStatus::Failed],
        ];
    }

    #[DataProvider('finishedStatuses')]
    public function test_finished_documents_are_left_alone(DocumentStatus $status): void
    {
        Embeddings::fake();
        $document = Document::factory()->create(['status' => $status]);

        $job = (new ProcessDocument($document))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(DocumentIngestionService::class));

        $this->assertSame($status, $document->fresh()->status);
        Embeddings::assertNothingGenerated();
    }

    public function test_only_one_job_per_document_is_queued_at_a_time(): void
    {
        Queue::fake();
        $document = Document::factory()->create();
        $other = Document::factory()->create();

        ProcessDocument::dispatch($document);
        ProcessDocument::dispatch($document);
        ProcessDocument::dispatch($other);

        $this->assertInstanceOf(ShouldBeUnique::class, new ProcessDocument($document));
        Queue::assertPushed(ProcessDocument::class, 2);
        Queue::assertPushed(ProcessDocument::class, fn (ProcessDocument $job): bool => $job->document->is($document));
        Queue::assertPushed(ProcessDocument::class, fn (ProcessDocument $job): bool => $job->document->is($other));
    }

    public function test_a_worker_waits_out_more_rate_limits_than_the_error_limit_and_completes(): void
    {
        $calls = $this->fakeEmbeddingOutcomes(array_fill(0, 6, 'rate limit'));
        $document = $this->storedDocument('Some text.');

        $this->processOnDatabaseQueue($document);

        $this->assertSame(7, $calls->count);
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
        $this->assertDatabaseEmpty('failed_jobs');
    }

    public function test_a_worker_fails_the_document_after_three_real_errors(): void
    {
        $calls = $this->fakeEmbeddingOutcomes(array_fill(0, 10, 'error'));
        $document = $this->storedDocument('Some text.');

        $this->processOnDatabaseQueue($document);

        $this->assertSame(3, $calls->count);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'Processing failed because of an unexpected error.',
        ]);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_rate_limits_between_errors_do_not_count_toward_the_error_limit(): void
    {
        $calls = $this->fakeEmbeddingOutcomes(['rate limit', 'error', 'rate limit', 'rate limit', 'error', 'rate limit']);
        $document = $this->storedDocument('Some text.');

        $this->processOnDatabaseQueue($document);

        $this->assertSame(7, $calls->count);
        $this->assertSame(DocumentStatus::Completed, $document->fresh()->status);
        $this->assertDatabaseEmpty('failed_jobs');
    }

    public function test_a_worker_fails_the_document_when_rate_limits_use_up_every_attempt(): void
    {
        $calls = $this->fakeEmbeddingOutcomes(array_fill(0, 20, 'rate limit'));
        $document = $this->storedDocument('Some text.');

        $this->processOnDatabaseQueue($document);

        $this->assertSame(10, $calls->count);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'failed',
            'error_message' => 'The embedding provider rate limit was reached. Retry the document later.',
        ]);
    }

    /**
     * Fake the embedding provider to fail with the given outcomes in order, then succeed.
     *
     * @param  list<'rate limit'|'error'>  $outcomes
     * @return object{count: int} A counter of provider calls.
     */
    private function fakeEmbeddingOutcomes(array $outcomes): object
    {
        $calls = (object) ['count' => 0];

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($outcomes, $calls): array {
            $outcome = $outcomes[$calls->count++] ?? null;

            return match ($outcome) {
                'rate limit' => throw RateLimitedException::forProvider('gemini', 429),
                'error' => throw new RuntimeException('Provider exploded.'),
                default => array_map(fn (): array => Embeddings::fakeEmbedding($prompt->dimensions), $prompt->inputs),
            };
        });

        return $calls;
    }

    /**
     * Queue the job on the database queue and run a worker until the queue is empty.
     *
     * Time is moved forward before each run so released and backed-off jobs become available.
     */
    private function processOnDatabaseQueue(Document $document): void
    {
        config(['queue.default' => 'database', 'logging.default' => 'null']);

        ProcessDocument::dispatch($document);

        for ($run = 0; $run < 20 && DB::table('jobs')->exists(); $run++) {
            $this->travel(20)->minutes();

            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        }

        $this->assertFalse(DB::table('jobs')->exists(), 'The job never left the queue.');
    }

    /**
     * Run the job on the sync queue, which marks it failed and rethrows the exception.
     */
    private function dispatchExpectingFailure(Document $document): void
    {
        try {
            ProcessDocument::dispatch($document);
            $this->fail('Expected the job to fail.');
        } catch (Throwable $exception) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $exception);
        }
    }

    /**
     * Store the contents on the documents disk and create a pending document pointing at them.
     */
    private function storedDocument(string $contents): Document
    {
        Storage::disk('documents')->put('stored.md', $contents);

        return Document::factory()->create(['storage_path' => 'stored.md', 'mime_type' => 'text/markdown']);
    }
}
