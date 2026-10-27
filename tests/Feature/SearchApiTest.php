<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\EmbeddingException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class SearchApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.top_k' => 5,
            'rag.min_score' => 0.0,
            'rag.search_rate_limit' => 30,
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(1.0), $prompt->inputs));
    }

    public function test_guests_receive_401(): void
    {
        $this->postJson('/api/search', ['query' => 'refunds'])->assertUnauthorized();

        Embeddings::assertNothingGenerated();
    }

    public function test_a_regular_user_gets_ranked_chunks_with_citation_fields_only(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $document = Document::factory()->completed()->create(['title' => 'Refund Policy']);
        $best = $this->chunk($document, 3, $this->vector(1.0), 'Refunds are issued within 30 days.', 'Policy > Refunds');
        $partial = $this->chunk($document, 1, $this->vector(0.6, 0.8), 'Partly related.');

        $response = $this->postJson('/api/search', ['query' => 'How do refunds work?']);

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'document_id' => $document->id,
                        'document_title' => 'Refund Policy',
                        'chunk_id' => $best->id,
                        'chunk_index' => 3,
                        'heading' => 'Policy > Refunds',
                        'content' => 'Refunds are issued within 30 days.',
                        'score' => 1.0,
                    ],
                    [
                        'document_id' => $document->id,
                        'document_title' => 'Refund Policy',
                        'chunk_id' => $partial->id,
                        'chunk_index' => 1,
                        'heading' => null,
                        'content' => 'Partly related.',
                        'score' => 0.6,
                    ],
                ],
                'meta' => [
                    'query' => 'How do refunds work?',
                    'limit' => 5,
                    'min_score' => 0.0,
                    'count' => 2,
                ],
            ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString($document->storage_path, $body);
        $this->assertStringNotContainsString('embedding', $body);
    }

    public function test_the_limit_parameter_is_respected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $document = Document::factory()->completed()->create();
        foreach (range(0, 3) as $index) {
            $this->chunk($document, $index, $this->vector(1.0));
        }

        $this->postJson('/api/search', ['query' => 'refunds', 'limit' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.limit', 2);
    }

    public function test_chunks_of_documents_that_are_not_completed_are_not_returned(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->chunk(Document::factory()->failed()->create(), 0, $this->vector(1.0));
        $this->chunk(Document::factory()->processing()->create(), 0, $this->vector(1.0));

        $this->postJson('/api/search', ['query' => 'refunds'])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_empty_result_is_an_empty_list(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/search', ['query' => 'refunds'])
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => ['query' => 'refunds', 'limit' => 5, 'min_score' => 0.0, 'count' => 0],
            ]);
    }

    public function test_a_regular_user_can_search_but_cannot_reach_document_management(): void
    {
        Storage::fake('documents');
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        $document = Document::factory()->completed()->create();

        $this->postJson('/api/search', ['query' => 'refunds'])->assertOk();

        $this->getJson('/api/documents')->assertForbidden();
        $this->getJson("/api/documents/{$document->id}")->assertForbidden();
        $this->post('/api/documents', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'Some text.')], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();
        $this->postJson("/api/documents/{$document->id}/retry")->assertForbidden();
        $this->assertModelExists($document);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'missing query' => [[], 'query'],
            'query too short' => [['query' => 'ab'], 'query'],
            'query too long' => [['query' => str_repeat('a', 501)], 'query'],
            'query not a string' => [['query' => ['refunds']], 'query'],
            'limit zero' => [['query' => 'refunds', 'limit' => 0], 'limit'],
            'limit above the cap' => [['query' => 'refunds', 'limit' => 21], 'limit'],
            'limit not an integer' => [['query' => 'refunds', 'limit' => 'many'], 'limit'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_are_rejected_without_calling_the_provider(array $payload, string $invalidField): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/search', $payload)->assertUnprocessable()->assertOnlyJsonValidationErrors([$invalidField]);

        Embeddings::assertNothingGenerated();
    }

    public function test_the_boundary_lengths_and_limits_are_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/search', ['query' => 'abc', 'limit' => 1])->assertOk();
        $this->postJson('/api/search', ['query' => str_repeat('a', 500), 'limit' => 20])->assertOk();
    }

    public function test_searches_are_rate_limited_per_user(): void
    {
        config(['rag.search_rate_limit' => 2]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/search', ['query' => 'refunds'])->assertOk();
        $this->postJson('/api/search', ['query' => 'refunds'])->assertOk();
        $this->postJson('/api/search', ['query' => 'refunds'])->assertTooManyRequests()->assertHeader('Retry-After');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/search', ['query' => 'refunds'])->assertOk();
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function providerFailures(): array
    {
        return [
            'SDK error' => [new AiException('Provider error: SECRET-PROVIDER-DETAIL')],
            'provider overloaded' => [ProviderOverloadedException::forProvider('gemini')],
            'unexpected vectors' => [new EmbeddingException('Expected 1 embeddings but received 0 (provider SECRET-PROVIDER-DETAIL).')],
            'raw HTTP error' => [new RequestException(new Response(new Psr7Response(500, [], '{"error":"SECRET-PROVIDER-DETAIL"}')))],
            'connection error' => [new ConnectionException('cURL error: SECRET-PROVIDER-DETAIL')],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_a_provider_failure_returns_a_generic_503_without_retry_after(Throwable $failure): void
    {
        Sanctum::actingAs(User::factory()->create());
        Embeddings::fake(fn () => throw $failure);

        $response = $this->postJson('/api/search', ['query' => 'refunds']);

        $response->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Search is temporarily unavailable. Please try again later.'])
            ->assertHeaderMissing('Retry-After');
        $this->assertStringNotContainsString('SECRET-PROVIDER-DETAIL', $response->getContent());
    }

    public function test_a_provider_rate_limit_returns_503_with_the_default_retry_after(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Embeddings::fake(fn () => throw RateLimitedException::forProvider('gemini', 429));

        $response = $this->postJson('/api/search', ['query' => 'refunds']);

        $response->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Search is temporarily unavailable. Please try again later.'])
            ->assertHeader('Retry-After', '30');
        $this->assertStringNotContainsString('gemini', $response->getContent());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providerRetryAfterHeaders(): array
    {
        return [
            'provider delay' => ['45', '45'],
            'delay above the cap' => ['86400', '300'],
            'zero' => ['0', '1'],
            'HTTP date' => ['Wed, 21 Oct 2026 07:28:00 GMT', '30'],
        ];
    }

    #[DataProvider('providerRetryAfterHeaders')]
    public function test_the_providers_retry_after_is_passed_on_within_limits(string $providerRetryAfter, string $expected): void
    {
        Sanctum::actingAs(User::factory()->create());
        $providerResponse = new RequestException(new Response(new Psr7Response(429, ['Retry-After' => $providerRetryAfter], '{"error":"SECRET-PROVIDER-DETAIL"}')));
        Embeddings::fake(fn () => throw RateLimitedException::forProvider('gemini', 429, $providerResponse));

        $response = $this->postJson('/api/search', ['query' => 'refunds']);

        $response->assertServiceUnavailable()->assertHeader('Retry-After', $expected);
        $this->assertStringNotContainsString('SECRET-PROVIDER-DETAIL', $response->getContent());
    }

    public function test_a_raw_429_from_the_provider_is_treated_as_a_rate_limit(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Embeddings::fake(fn () => throw new RequestException(new Response(new Psr7Response(429, ['Retry-After' => '12']))));

        $this->postJson('/api/search', ['query' => 'refunds'])
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '12');
    }

    /**
     * Build a vector of the configured dimension whose first components are the given values.
     *
     * @return list<float>
     */
    private function vector(float ...$leading): array
    {
        return array_pad(array_values($leading), config('rag.embedding_dimensions'), 0.0);
    }

    /**
     * Store one chunk of the document.
     *
     * @param  list<float>  $embedding
     */
    private function chunk(Document $document, int $index, array $embedding, string $content = 'Chunk text.', ?string $heading = null): DocumentChunk
    {
        return DocumentChunk::factory()->for($document)->create([
            'chunk_index' => $index,
            'embedding' => $embedding,
            'content' => $content,
            'metadata' => ['heading' => $heading],
        ]);
    }
}
