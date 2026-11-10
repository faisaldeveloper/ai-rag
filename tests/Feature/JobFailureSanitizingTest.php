<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Jobs\ReembedDocument;
use App\Jobs\UnexpectedJobFailure;
use App\Models\Document;
use App\Services\RAG\DocumentIngestionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * An unexpected exception inside a document job can carry the document's
 * text (in SQL bindings or a message), so it must reach neither the log, nor
 * failed_jobs.exception, nor documents.error_message.
 */
class JobFailureSanitizingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Sentinel = 'SECRET_SENTINEL_12345';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config([
            'rag.embedding_provider' => 'gemini',
            'rag.embedding_model' => 'test-embedding-model',
        ]);

        $this->logs = $this->captureLogs();
    }

    /**
     * @return array<string, array{0: string, 1: class-string<Throwable>, 2: string|null}>
     */
    public static function unexpectedFailures(): array
    {
        return [
            'query exception with the chunk text in its bindings' => ['query', QueryException::class, '22P02'],
            'runtime exception with the chunk text in its message' => ['runtime', RuntimeException::class, null],
        ];
    }

    #[DataProvider('unexpectedFailures')]
    public function test_a_failed_ingestion_keeps_document_text_out_of_logs_failed_jobs_and_the_error_message(string $failure, string $exceptionClass, ?string $code): void
    {
        $calls = $this->failEmbeddingWith($failure);
        $document = $this->storedDocument(DocumentStatus::Pending);

        $this->processOnDatabaseQueue(fn () => ProcessDocument::dispatch($document));

        $this->assertSame(3, $calls->count, 'The retry and error limits must be unchanged.');
        $document->refresh();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('Processing failed because of an unexpected error.', $document->error_message);
        $this->assertFailedJobIsSanitized($document);
        $this->assertUnexpectedFailuresWereLogged(ProcessDocument::class, $document, $exceptionClass, $code, attempts: 3);
    }

    #[DataProvider('unexpectedFailures')]
    public function test_a_failed_re_embed_keeps_document_text_out_of_logs_and_failed_jobs(string $failure, string $exceptionClass, ?string $code): void
    {
        $calls = $this->failEmbeddingWith($failure);
        $document = $this->storedDocument(DocumentStatus::Completed);

        $this->processOnDatabaseQueue(fn () => ReembedDocument::dispatch($document));

        $this->assertSame(3, $calls->count, 'The retry and error limits must be unchanged.');
        $document->refresh();
        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertNull($document->error_message);
        $this->assertFailedJobIsSanitized($document);
        $this->assertUnexpectedFailuresWereLogged(ReembedDocument::class, $document, $exceptionClass, $code, attempts: 3);
    }

    public function test_the_replacement_exception_does_not_chain_the_original(): void
    {
        $this->failEmbeddingWith('runtime');
        $document = $this->storedDocument(DocumentStatus::Pending);
        $job = (new ProcessDocument($document))->withFakeQueueInteractions();

        try {
            $job->handle(app(DocumentIngestionService::class));
            $this->fail('Expected the job to fail.');
        } catch (UnexpectedJobFailure $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertSame($document->id, $exception->documentId);
            $this->assertStringNotContainsString(self::Sentinel, (string) $exception);
        }

        $job->assertNotReleased();
    }

    /**
     * Fake the embedding provider to fail every call with an exception that carries the chunk text.
     *
     * @return object{count: int} A counter of provider calls.
     */
    private function failEmbeddingWith(string $failure): object
    {
        $calls = (object) ['count' => 0];

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($failure, $calls): never {
            $calls->count++;
            $chunkText = implode(' ', $prompt->inputs);
            $this->assertStringContainsString(self::Sentinel, $chunkText);

            if ($failure === 'query') {
                // A real query failure; the savepoint keeps the test's transaction usable.
                DB::transaction(fn () => DB::select('select ?::integer', [$chunkText]));
            }

            throw new RuntimeException('Could not embed: '.$chunkText);
        });

        return $calls;
    }

    /**
     * Dispatch onto the database queue and run a worker until the queue is empty.
     *
     * Time is moved forward before each run so backed-off jobs become available.
     */
    private function processOnDatabaseQueue(callable $dispatch): void
    {
        config(['queue.default' => 'database']);

        $dispatch();

        for ($run = 0; $run < 10 && DB::table('jobs')->exists(); $run++) {
            $this->travel(20)->minutes();

            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        }

        $this->assertFalse(DB::table('jobs')->exists(), 'The job never left the queue.');
    }

    private function assertFailedJobIsSanitized(Document $document): void
    {
        $failedJobs = DB::table('failed_jobs')->pluck('exception');

        $this->assertCount(1, $failedJobs);
        $this->assertStringContainsString(UnexpectedJobFailure::class, $failedJobs[0]);
        $this->assertStringContainsString("document {$document->id}", $failedJobs[0]);
        $this->assertStringNotContainsString(self::Sentinel, $failedJobs[0]);
    }

    /**
     * @param  class-string  $job
     * @param  class-string<Throwable>  $exceptionClass
     */
    private function assertUnexpectedFailuresWereLogged(string $job, Document $document, string $exceptionClass, ?string $code, int $attempts): void
    {
        $failures = array_values(array_filter(
            $this->logs->getRecords(),
            fn (LogRecord $record): bool => $record->message === 'A document job failed unexpectedly.',
        ));

        $this->assertCount($attempts, $failures);
        $this->assertSame([
            'job' => $job,
            'document_id' => $document->id,
            'exception' => $exceptionClass,
            'code' => $code ?? 0,
        ], $failures[0]->context);
        $this->assertStringNotContainsString(self::Sentinel, $this->capturedLogText($this->logs));

        foreach ($this->logs->getRecords() as $record) {
            $this->assertNotInstanceOf(Throwable::class, $record->context['exception'] ?? null);
        }
    }

    /**
     * Store a document whose body (and so its only chunk) contains the sentinel.
     */
    private function storedDocument(DocumentStatus $status): Document
    {
        Storage::disk('documents')->put('stored.md', 'Refund policy '.self::Sentinel.'.');
        $factory = $status === DocumentStatus::Completed ? Document::factory()->completed() : Document::factory();

        return $factory->create(['storage_path' => 'stored.md', 'mime_type' => 'text/markdown', 'title' => 'Policy '.self::Sentinel]);
    }
}
