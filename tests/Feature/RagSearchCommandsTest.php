<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class RagSearchCommandsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Temporary query files, removed after each test.
     *
     * @var list<string>
     */
    private array $temporaryFiles = [];

    /**
     * Every text sent to the fake embedding provider, in order.
     *
     * @var list<string>
     */
    private array $embeddedInputs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['rag.top_k' => 5, 'rag.min_score' => 0.0]);

        Embeddings::fake(function (EmbeddingsPrompt $prompt): array {
            array_push($this->embeddedInputs, ...$prompt->inputs);

            return array_map(fn (): array => $this->vector(1.0), $prompt->inputs);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_rag_search_prints_rank_score_title_heading_and_a_100_character_preview_ignoring_min_score(): void
    {
        config(['rag.min_score' => 0.9]);
        User::factory()->admin()->create();
        $document = Document::factory()->completed()->create(['title' => 'Refund Policy']);
        $content = str_repeat('Refunds are issued within thirty days. ', 5);
        $this->chunk($document, 0, $this->vector(1.0), "Refunds\n\n".$content, 'Policy > Refunds');
        $this->chunk($document, 1, $this->vector(0.6, 0.8), 'Partly related.');

        $this->artisan('rag:search', ['query' => 'How do refunds work?'])
            ->expectsTable(['#', 'Score', 'Document', 'Heading', 'Content'], [
                [1, '1.0000', 'Refund Policy', 'Policy > Refunds', mb_substr('Refunds '.$content, 0, 100)],
                [2, '0.6000', 'Refund Policy', '', 'Partly related.'],
            ])
            ->assertSuccessful();
    }

    public function test_rag_search_respects_the_limit_option(): void
    {
        User::factory()->admin()->create();
        $document = Document::factory()->completed()->create();
        $this->chunk($document, 0, $this->vector(1.0), 'First.');
        $this->chunk($document, 1, $this->vector(0.6, 0.8), 'Second.');

        $this->artisan('rag:search', ['query' => 'refunds', '--limit' => 1])
            ->expectsOutputToContain('First.')
            ->doesntExpectOutputToContain('Second.')
            ->assertSuccessful();
    }

    public function test_rag_search_reports_when_nothing_is_found(): void
    {
        User::factory()->admin()->create();

        $this->artisan('rag:search', ['query' => 'refunds'])
            ->expectsOutputToContain('No chunks found.')
            ->assertSuccessful();
    }

    public function test_rag_search_searches_as_the_given_user_and_fails_without_one(): void
    {
        $this->artisan('rag:search', ['query' => 'refunds'])
            ->expectsOutputToContain('No user to search as.')
            ->assertFailed();

        $this->artisan('rag:search', ['query' => 'refunds', '--user' => 'missing@example.com'])->assertFailed();

        $user = User::factory()->create(['email' => 'reader@example.com']);
        $this->artisan('rag:search', ['query' => 'refunds', '--user' => $user->email])->assertSuccessful();
    }

    public function test_rag_bench_search_runs_every_query_in_the_default_file_and_reports_average_latency(): void
    {
        User::factory()->admin()->create();
        $this->chunk(Document::factory()->completed()->create(), 0, $this->vector(1.0));

        $this->artisan('rag:bench-search')
            ->expectsOutputToContain('Average embed latency')
            ->expectsOutputToContain('Average SQL latency')
            ->expectsOutputToContain('Average total latency')
            ->assertSuccessful();

        $this->assertCount(10, array_unique($this->embeddedInputs));
    }

    public function test_rag_bench_search_skips_blank_lines_and_comments(): void
    {
        User::factory()->admin()->create();
        $path = $this->queryFile("# A comment\n\nFirst question?\n   \nSecond question?\n");

        $this->artisan('rag:bench-search', ['--file' => $path])->assertSuccessful();

        $this->assertSame(['First question?', 'Second question?'], $this->embeddedInputs);
    }

    public function test_rag_bench_search_fails_for_a_missing_or_empty_file(): void
    {
        User::factory()->admin()->create();

        $this->artisan('rag:bench-search', ['--file' => 'tests/Fixtures/rag/missing.txt'])
            ->expectsOutputToContain('not found')
            ->assertFailed();

        $this->artisan('rag:bench-search', ['--file' => $this->queryFile("# only a comment\n")])
            ->expectsOutputToContain('contains no queries')
            ->assertFailed();

        Embeddings::assertNothingGenerated();
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

    /**
     * Write a temporary query file and return its path.
     */
    private function queryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bench-queries-');
        file_put_contents($path, $contents);

        return $this->temporaryFiles[] = $path;
    }
}
