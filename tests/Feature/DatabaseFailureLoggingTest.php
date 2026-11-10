<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\Retriever;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PDOException;
use Tests\TestCase;
use Throwable;

/**
 * A database exception's message holds the SQL with its bindings, which can
 * include question text and chunk content, so only the exception class, the
 * SQLSTATE, the route or job, and the IDs involved may be logged.
 */
class DatabaseFailureLoggingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const Sentinel = 'SECRET_SENTINEL_12345';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = $this->captureLogs();
    }

    public function test_a_query_exception_during_retrieval_logs_only_safe_details(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $document = Document::factory()->completed()->create(['title' => 'Policy '.self::Sentinel]);
        DocumentChunk::factory()->for($document)->create(['content' => 'Chunk text '.self::Sentinel]);
        $this->mock(Retriever::class)->shouldReceive('search')->andReturnUsing(
            fn (User $user, string $question) => $this->failQueryWith([$question, DocumentChunk::query()->value('content')]),
        );
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/chat', ['message' => 'Question '.self::Sentinel]);

        $response->assertServerError();
        $this->assertStringNotContainsString(self::Sentinel, $response->getContent());
        $this->assertSame([[
            'exception' => QueryException::class,
            'sqlstate' => '22P02',
            'route' => 'POST /api/chat',
            'job' => null,
            'document_id' => null,
            'conversation_id' => null,
            'request_id' => $response->headers->get('X-Request-Id'),
        ]], $this->recordContexts());
        $this->assertLogsDoNotContainTheSentinel();
    }

    public function test_the_conversation_id_from_the_route_is_logged_when_known(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => self::Sentinel]);
        Conversation::retrieved(fn () => $this->failQueryWith([self::Sentinel]));
        Sanctum::actingAs($user);

        $this->getJson("/api/conversations/{$conversation->id}")->assertServerError();

        $this->assertSame('GET /api/conversations/{conversation}', $this->recordContexts()[0]['route']);
        $this->assertSame($conversation->id, $this->recordContexts()[0]['conversation_id']);
        $this->assertLogsDoNotContainTheSentinel();
    }

    public function test_the_default_reporter_does_not_also_log_a_reported_query_exception(): void
    {
        try {
            $this->failQueryWith([self::Sentinel]);
        } catch (QueryException $exception) {
            report($exception);
        }

        $this->assertCount(1, $this->logs->getRecords());
        $record = $this->logs->getRecords()[0];
        $this->assertSame('Database query failed.', $record->message);
        $this->assertSame(['exception', 'sqlstate', 'route', 'job', 'document_id', 'conversation_id', 'request_id'], array_keys($record->context));
        $this->assertSame(QueryException::class, $record->context['exception']);
        $this->assertLogsDoNotContainTheSentinel();
    }

    public function test_a_pdo_exception_is_logged_with_its_sqlstate_only(): void
    {
        $exception = new PDOException('SQLSTATE[HY000]: General error: '.self::Sentinel);
        $exception->errorInfo = ['HY000', 7, 'General error: '.self::Sentinel];

        report($exception);

        $this->assertCount(1, $this->logs->getRecords());
        $this->assertSame(PDOException::class, $this->recordContexts()[0]['exception']);
        $this->assertSame('HY000', $this->recordContexts()[0]['sqlstate']);
        $this->assertLogsDoNotContainTheSentinel();
    }

    public function test_other_exceptions_are_still_reported_normally(): void
    {
        report(new \RuntimeException('Ordinary application error.'));

        $this->assertTrue($this->logs->hasErrorThatContains('Ordinary application error.'));
    }

    /**
     * Run a real query that fails because a bound value is not an integer.
     *
     * The savepoint keeps the test's own transaction usable after the failure.
     *
     * @param  list<mixed>  $bindings
     */
    private function failQueryWith(array $bindings): never
    {
        $placeholders = implode(', ', array_fill(0, count($bindings), '?::integer'));

        DB::transaction(fn () => DB::select("select {$placeholders}", $bindings));

        $this->fail('Expected the query to fail.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordContexts(): array
    {
        return array_map(fn (LogRecord $record): array => $record->context, $this->logs->getRecords());
    }

    /**
     * Assert that something was logged and no record (message, context, or extra data) contains the sentinel.
     */
    private function assertLogsDoNotContainTheSentinel(): void
    {
        $this->assertNotSame([], $this->logs->getRecords(), 'Expected the failure to be logged.');
        $this->assertStringNotContainsString(self::Sentinel, $this->capturedLogText($this->logs));

        foreach ($this->logs->getRecords() as $record) {
            $this->assertNotInstanceOf(Throwable::class, $record->context['exception'] ?? null);
        }
    }
}
