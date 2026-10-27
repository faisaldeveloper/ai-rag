<?php

namespace Tests\Feature;

use App\Enums\EmbeddingTaskType;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\EmbeddingService;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.embedding_provider' => 'gemini',
            'rag.embedding_model' => 'test-embedding-model',
            'rag.embedding_dimensions' => 8,
            'rag.embedding_batch_size' => 32,
            'rag.embedding_task_types' => true,
        ]);
    }

    public function test_it_embeds_texts_with_the_configured_provider_model_and_dimensions(): void
    {
        Embeddings::fake();

        $embeddings = app(EmbeddingService::class)->embed(['first', 'second']);

        $this->assertCount(2, $embeddings);
        $this->assertCount(8, $embeddings[0]);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['first', 'second']
            && $prompt->dimensions === 8
            && $prompt->model === 'test-embedding-model'
            && $prompt->provider->name() === 'gemini');
    }

    public function test_it_exposes_the_configured_provider_and_model(): void
    {
        $service = app(EmbeddingService::class);

        $this->assertSame('gemini', $service->provider());
        $this->assertSame('test-embedding-model', $service->model());
        $this->assertSame(8, $service->dimensions());
    }

    public function test_it_rejects_a_response_with_the_wrong_number_of_vectors(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(8)]]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Expected 2 embeddings but received 1');

        app(EmbeddingService::class)->embed(['first', 'second']);
    }

    public function test_it_rejects_vectors_with_the_wrong_dimension(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(4)]]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Embedding 0 has 4 dimensions; expected 8');

        app(EmbeddingService::class)->embed(['first']);
    }

    public function test_it_embeds_in_batches_of_the_configured_size_and_keeps_the_input_order(): void
    {
        config(['rag.embedding_batch_size' => 2]);
        $batches = [];
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$batches): array {
            $batches[] = $prompt->inputs;

            return array_map(fn (string $input): array => array_fill(0, 8, (float) substr($input, 5)), $prompt->inputs);
        });

        $embeddings = app(EmbeddingService::class)->embed(['text-0', 'text-1', 'text-2', 'text-3', 'text-4']);

        $this->assertSame([['text-0', 'text-1'], ['text-2', 'text-3'], ['text-4']], $batches);
        $this->assertSame([0.0, 1.0, 2.0, 3.0, 4.0], array_column($embeddings, 0));
    }

    public function test_it_rejects_wrong_dimensions_in_a_later_batch_with_the_overall_index(): void
    {
        config(['rag.embedding_batch_size' => 2]);
        Embeddings::fake([
            [Embeddings::fakeEmbedding(8), Embeddings::fakeEmbedding(8)],
            [Embeddings::fakeEmbedding(8), Embeddings::fakeEmbedding(4)],
        ]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Embedding 3 has 4 dimensions; expected 8');

        app(EmbeddingService::class)->embed(['a', 'b', 'c', 'd']);
    }

    public function test_embedding_nothing_does_not_call_the_provider(): void
    {
        Embeddings::fake();

        $this->assertSame([], app(EmbeddingService::class)->embed([]));

        Embeddings::assertNothingGenerated();
    }

    public function test_it_sends_the_task_type_to_gemini_when_task_types_are_enabled(): void
    {
        Embeddings::fake();

        app(EmbeddingService::class)->embed(['first'], EmbeddingTaskType::RetrievalDocument);
        app(EmbeddingService::class)->embed(['question'], EmbeddingTaskType::RetrievalQuery);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['first']
            && $prompt->providerOptions === ['taskType' => 'RETRIEVAL_DOCUMENT']);
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['question']
            && $prompt->providerOptions === ['taskType' => 'RETRIEVAL_QUERY']);
        $this->assertTrue(app(EmbeddingService::class)->usesTaskTypes());
    }

    public function test_it_sends_the_task_type_with_every_batch(): void
    {
        config(['rag.embedding_batch_size' => 1]);
        Embeddings::fake();

        app(EmbeddingService::class)->embed(['first', 'second'], EmbeddingTaskType::RetrievalDocument);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['first'] && $prompt->providerOptions === ['taskType' => 'RETRIEVAL_DOCUMENT']);
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['second'] && $prompt->providerOptions === ['taskType' => 'RETRIEVAL_DOCUMENT']);
    }

    public function test_it_sends_no_task_type_when_task_types_are_disabled(): void
    {
        config(['rag.embedding_task_types' => false]);
        Embeddings::fake();

        app(EmbeddingService::class)->embed(['first'], EmbeddingTaskType::RetrievalDocument);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->providerOptions === []);
        $this->assertFalse(app(EmbeddingService::class)->usesTaskTypes());
    }

    public function test_it_sends_no_task_type_to_providers_other_than_gemini(): void
    {
        config(['rag.embedding_provider' => 'openai']);
        Embeddings::fake();

        app(EmbeddingService::class)->embed(['first'], EmbeddingTaskType::RetrievalDocument);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->provider->name() === 'openai'
            && $prompt->providerOptions === []);
        $this->assertFalse(app(EmbeddingService::class)->usesTaskTypes());
    }

    public function test_a_renamed_gemini_provider_still_gets_task_types(): void
    {
        config([
            'ai.providers.google' => ['driver' => 'gemini', 'key' => 'test-key'],
            'rag.embedding_provider' => 'google',
        ]);
        Embeddings::fake();

        app(EmbeddingService::class)->embed(['first'], EmbeddingTaskType::RetrievalQuery);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->providerOptions === ['taskType' => 'RETRIEVAL_QUERY']);
    }

    public function test_it_sends_no_task_type_when_none_is_given(): void
    {
        Embeddings::fake();

        app(EmbeddingService::class)->embed(['first']);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->providerOptions === []);
    }
}
