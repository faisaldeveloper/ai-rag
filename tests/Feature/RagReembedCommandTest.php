<?php

namespace Tests\Feature;

use App\Jobs\ReembedDocument;
use App\Models\Document;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RagReembedCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_one_completed_document_is_queued(): void
    {
        $document = Document::factory()->completed()->create();
        Document::factory()->completed()->create();

        $this->artisan('rag:reembed', ['--document' => $document->id])
            ->expectsOutputToContain("Queued document [{$document->id}] for re-embedding.")
            ->assertSuccessful();

        Queue::assertPushed(ReembedDocument::class, 1);
        Queue::assertPushed(ReembedDocument::class, fn (ReembedDocument $job): bool => $job->document->is($document));
    }

    public function test_all_completed_documents_are_queued_and_others_are_skipped(): void
    {
        $completed = Document::factory()->completed()->count(3)->create();
        Document::factory()->create();
        Document::factory()->processing()->create();
        Document::factory()->failed()->create();

        $this->artisan('rag:reembed', ['--all' => true])
            ->expectsOutputToContain('Queued 3 completed document(s) for re-embedding.')
            ->assertSuccessful();

        Queue::assertPushed(ReembedDocument::class, 3);
        foreach ($completed as $document) {
            Queue::assertPushed(ReembedDocument::class, fn (ReembedDocument $job): bool => $job->document->is($document));
        }
    }

    public function test_a_document_that_is_not_completed_is_rejected(): void
    {
        $document = Document::factory()->processing()->create();

        $this->artisan('rag:reembed', ['--document' => $document->id])
            ->expectsOutputToContain('only completed documents can be re-embedded')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_a_missing_document_is_rejected(): void
    {
        $this->artisan('rag:reembed', ['--document' => 999])
            ->expectsOutputToContain('Document [999] does not exist.')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_exactly_one_of_the_options_is_required(): void
    {
        $document = Document::factory()->completed()->create();

        $this->artisan('rag:reembed')->expectsOutputToContain('Pass either --document=<id> or --all.')->assertFailed();
        $this->artisan('rag:reembed', ['--document' => $document->id, '--all' => true])->assertFailed();

        Queue::assertNothingPushed();
    }
}
