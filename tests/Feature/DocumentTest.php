<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_documents_default_to_pending(): void
    {
        $document = Document::create(Document::factory()->raw(['status' => null]));

        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'pending']);
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        $document = Document::factory()->create();

        $document->update(['status' => DocumentStatus::Completed, 'title' => 'Renamed']);

        $this->assertSame(DocumentStatus::Pending, $document->fresh()->status);
        $this->assertSame('Renamed', $document->fresh()->title);
    }

    public function test_document_belongs_to_its_uploader_and_has_ordered_chunks(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->for($user)->create();
        DocumentChunk::factory()->for($document)->create(['chunk_index' => 1]);
        DocumentChunk::factory()->for($document)->create(['chunk_index' => 0]);

        $this->assertTrue($document->user->is($user));
        $this->assertTrue($user->documents->first()->is($document));
        $this->assertSame([0, 1], $document->chunks->pluck('chunk_index')->all());
    }

    public function test_successful_ingestion_moves_through_processing_to_completed(): void
    {
        $document = Document::factory()->create();

        $document->transitionTo(DocumentStatus::Processing);
        $document->transitionTo(DocumentStatus::Completed);

        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'completed', 'error_message' => null]);
    }

    public function test_failing_records_the_error_and_retrying_clears_it(): void
    {
        $document = Document::factory()->processing()->create();

        $document->transitionTo(DocumentStatus::Failed, 'Unreadable PDF.');

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'failed', 'error_message' => 'Unreadable PDF.']);

        $document->transitionTo(DocumentStatus::Pending);

        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertNull($document->error_message);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'pending', 'error_message' => null]);
    }

    public function test_error_message_is_ignored_for_non_failed_statuses(): void
    {
        $document = Document::factory()->create();

        $document->transitionTo(DocumentStatus::Processing, 'Should not be stored.');

        $this->assertNull($document->error_message);
    }

    public function test_invalid_transition_throws_and_leaves_the_document_unchanged(): void
    {
        $document = Document::factory()->completed()->create();

        try {
            $document->transitionTo(DocumentStatus::Processing);
            $this->fail('Expected a LogicException.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('[completed] to [processing]', $exception->getMessage());
        }

        $this->assertSame(DocumentStatus::Completed, $document->status);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'completed']);
    }

    public function test_pending_document_cannot_skip_processing(): void
    {
        $document = Document::factory()->create();

        $this->expectException(LogicException::class);

        $document->transitionTo(DocumentStatus::Completed);
    }

    public function test_stale_instance_cannot_repeat_a_transition_another_worker_already_made(): void
    {
        $document = Document::factory()->create();
        $staleCopy = Document::find($document->id);

        $document->transitionTo(DocumentStatus::Processing);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('changed by another process');

        $staleCopy->transitionTo(DocumentStatus::Processing);
    }

    public function test_the_same_user_cannot_upload_the_same_file_twice(): void
    {
        $existing = Document::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Document::factory()->create(['user_id' => $existing->user_id, 'checksum' => $existing->checksum]);
    }

    public function test_different_users_cannot_upload_the_same_file(): void
    {
        $existing = Document::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Document::factory()->create(['checksum' => $existing->checksum]);
    }

    public function test_documents_without_an_uploader_are_also_deduplicated_by_checksum(): void
    {
        $existing = Document::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Document::factory()->withoutUser()->create(['checksum' => $existing->checksum]);
    }

    public function test_searchable_returns_every_completed_document_regardless_of_uploader(): void
    {
        $ownedByAdmin = Document::factory()->for(User::factory()->admin())->completed()->create();
        $ownedByUser = Document::factory()->completed()->create();
        $withoutUploader = Document::factory()->withoutUser()->completed()->create();
        Document::factory()->create();
        Document::factory()->processing()->create();
        Document::factory()->failed()->create();

        $this->assertEqualsCanonicalizing(
            [$ownedByAdmin->id, $ownedByUser->id, $withoutUploader->id],
            Document::searchable()->pluck('id')->all(),
        );
    }

    public function test_deleting_the_uploader_keeps_the_document(): void
    {
        $document = Document::factory()->create();

        $document->user->delete();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'user_id' => null]);
    }
}
