<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Jobs\ReembedDocument;
use App\Models\Document;
use App\Models\User;
use App\Services\RAG\DocumentIngestionService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Provider error text can echo credentials or the text that was embedded, so
 * it must never reach the log: only the exception class, the provider's HTTP
 * status, and the request ID may be logged.
 */
class ProviderFailureLoggingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FakeSecret = 'sk-test-SECRET-4242';

    private const FakeDocumentSentence = 'Refunds are issued within thirty days of purchase.';

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

    public function test_a_search_provider_failure_logs_only_the_class_status_and_request_id(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Embeddings::fake(fn () => throw $this->leakyRateLimit());

        $response = $this->postJson('/api/search', ['query' => 'How do refunds work?']);

        $response->assertServiceUnavailable()->assertHeader('X-Request-Id');
        $this->assertSame([[
            'exception' => RateLimitedException::class,
            'status' => 429,
            'request_id' => $response->headers->get('X-Request-Id'),
        ]], array_map(fn ($record): array => $record->context, $this->logs->getRecords()));
        $this->assertLogsContainNoProviderText();
    }

    public function test_a_search_failure_without_an_http_response_logs_a_null_status(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Embeddings::fake(fn () => throw $this->leakyProviderError());

        $this->postJson('/api/search', ['query' => 'How do refunds work?'])->assertServiceUnavailable();

        $this->assertSame(AiException::class, $this->logs->getRecords()[0]->context['exception']);
        $this->assertNull($this->logs->getRecords()[0]->context['status']);
        $this->assertLogsContainNoProviderText();
    }

    public function test_the_queue_worker_logs_an_ingestion_provider_failure_without_its_text(): void
    {
        config(['queue.default' => 'database']);
        Embeddings::fake(fn () => throw $this->leakyProviderError(status: 500));
        $document = $this->storedDocument();
        Context::add('request_id', 'upload-request-1');
        ProcessDocument::dispatch($document);
        Context::flush();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertTrue($this->logs->hasErrorThatContains('Embedding provider request failed.'));
        $context = $this->logs->getRecords()[0]->context;
        $this->assertSame([AiException::class, 500, 'upload-request-1'], [$context['exception'], $context['status'], $context['request_id']]);
        $this->assertLogsContainNoProviderText();
    }

    public function test_a_failed_re_embed_logs_only_the_document_class_status_and_request_id(): void
    {
        Embeddings::fake(fn () => throw $this->leakyRateLimit());
        $document = $this->storedDocument(completed: true);
        Context::add('request_id', 'reembed-command-1');
        $job = (new ReembedDocument($document))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        try {
            $job->handle(app(DocumentIngestionService::class));
            $this->fail('Expected the rate limit to be rethrown on the last attempt.');
        } catch (RateLimitedException $exception) {
            report($exception);
            $job->failed($exception);
        }

        $records = $this->logs->getRecords();
        $this->assertCount(2, $records);
        $this->assertSame([
            'document_id' => $document->id,
            'exception' => RateLimitedException::class,
            'status' => 429,
            'request_id' => 'reembed-command-1',
        ], $records[1]->context);
        $this->assertLogsContainNoProviderText();
    }

    public function test_exceptions_that_are_not_provider_failures_are_still_reported_normally(): void
    {
        report(new RuntimeException('Ordinary application error.'));

        $this->assertTrue($this->logs->hasErrorThatContains('Ordinary application error.'));
    }

    /**
     * A rate limit whose message and wrapped provider response contain secret and document text.
     */
    private function leakyRateLimit(): Throwable
    {
        return new RateLimitedException(
            'Provider rejected key '.self::FakeSecret.' while embedding "'.self::FakeDocumentSentence.'"',
            429,
            $this->leakyProviderResponse(429),
        );
    }

    /**
     * A provider error whose message (and wrapped provider response, if a status is given) contain secret and document text.
     */
    private function leakyProviderError(?int $status = null): Throwable
    {
        return new AiException(
            'Key '.self::FakeSecret.' failed on: '.self::FakeDocumentSentence,
            $status ?? 0,
            $status !== null ? $this->leakyProviderResponse($status) : null,
        );
    }

    /**
     * An HTTP client exception whose provider response body contains secret and document text.
     */
    private function leakyProviderResponse(int $status): RequestException
    {
        return new RequestException(new Response(new Psr7Response(
            $status,
            ['Retry-After' => '5'],
            json_encode(['error' => ['message' => 'Invalid key '.self::FakeSecret.' for input: '.self::FakeDocumentSentence]]),
        )));
    }

    /**
     * Assert that no log record (message, context, or extra data) contains the fake secret or document text.
     */
    private function assertLogsContainNoProviderText(): void
    {
        $text = $this->capturedLogText($this->logs);

        $this->assertNotSame([], $this->logs->getRecords(), 'Expected the failure to be logged.');
        $this->assertStringNotContainsString(self::FakeSecret, $text);
        $this->assertStringNotContainsString('thirty days', $text);
    }

    /**
     * Store a document's file and create a pending (or completed) document pointing at it.
     */
    private function storedDocument(bool $completed = false): Document
    {
        Storage::disk('documents')->put('stored.md', 'Some text.');
        $factory = $completed ? Document::factory()->completed() : Document::factory();

        return $factory->create(['storage_path' => 'stored.md', 'mime_type' => 'text/markdown']);
    }
}
