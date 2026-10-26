<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Temporary files created for uploads, removed after each test.
     *
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_guests_receive_401_on_every_document_route(): void
    {
        $document = Document::factory()->create();

        $this->getJson('/api/documents')->assertUnauthorized();
        $this->postJson('/api/documents')->assertUnauthorized();
        $this->getJson("/api/documents/{$document->id}")->assertUnauthorized();
        $this->deleteJson("/api/documents/{$document->id}")->assertUnauthorized();
        $this->postJson("/api/documents/{$document->id}/retry")->assertUnauthorized();
    }

    public function test_guests_receive_json_401_even_without_an_accept_header(): void
    {
        $this->get('/api/documents')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_personal_access_token_authenticates_and_an_invalid_one_is_rejected(): void
    {
        $token = User::factory()->admin()->create()->createToken('cli')->plainTextToken;

        $this->withToken($token)->getJson('/api/documents')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken('1|not-a-real-token')->getJson('/api/documents')->assertUnauthorized();
    }

    public function test_admin_uploads_a_text_file_as_a_pending_document_on_the_private_disk(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $contents = "Refund policy\n\nRefunds are issued within 30 days.\n";

        $response = $this->post('/api/documents', [
            'file' => $this->textUpload('../../Refund Policy.txt', $contents),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Refund Policy')
            ->assertJsonPath('data.original_filename', 'Refund Policy.txt')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checksum', hash('sha256', $contents))
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.error_message');

        $document = Document::sole();
        $this->assertTrue($document->user->is($admin));
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.txt$/', $document->storage_path);
        Storage::disk('documents')->assertExists($document->storage_path);
        $this->assertSame($contents, Storage::disk('documents')->get($document->storage_path));
        Queue::assertNothingPushed();
    }

    public function test_admin_uploads_markdown_containing_code_with_a_custom_title(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $markdown = "# Setup\n\n```c\n#include <stdio.h>\nint main(void) { return 0; }\n```\n";

        $response = $this->postJson('/api/documents', [
            'file' => $this->textUpload('setup.md', $markdown),
            'title' => 'Setup guide',
        ]);

        $response->assertCreated()->assertJsonPath('data.title', 'Setup guide');
        $this->assertStringEndsWith('.md', Document::sole()->storage_path);
    }

    public function test_upload_requires_a_file(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/documents', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file field is required.']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function wrongFileTypes(): array
    {
        $png = base64_decode('iVBORw0KGBgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        return [
            'binary renamed to .txt' => ['image.txt', $png, 'The file must contain plain text or Markdown.'],
            'empty file' => ['empty.md', '', 'The file must contain plain text or Markdown.'],
            'text with a disallowed extension' => ['notes.pdf', 'Just some text.', 'The file field must have one of the following extensions: txt, md.'],
            'text without an extension' => ['notes', 'Just some text.', 'The file field must have one of the following extensions: txt, md.'],
        ];
    }

    #[DataProvider('wrongFileTypes')]
    public function test_upload_rejects_wrong_file_types(string $filename, string $contents, string $message): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/documents', ['file' => $this->textUpload($filename, $contents)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => $message]);

        $this->assertDatabaseEmpty('documents');
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    public function test_upload_rejects_files_over_5_mb(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/documents', ['file' => $this->textUpload('big.txt', str_repeat('a', 5 * 1024 * 1024 + 1))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file field must not be greater than 5120 kilobytes.']);

        $this->assertDatabaseEmpty('documents');
    }

    public function test_upload_accepts_a_file_of_exactly_5_mb(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/documents', ['file' => $this->textUpload('big.txt', str_repeat('a', 5 * 1024 * 1024))])
            ->assertCreated();
    }

    public function test_uploading_the_same_content_twice_is_rejected_for_the_same_admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/documents', ['file' => $this->textUpload('first.txt', 'Same content.')])->assertCreated();

        $this->postJson('/api/documents', ['file' => $this->textUpload('renamed.md', 'Same content.')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'This file has already been uploaded.']);

        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    public function test_uploading_content_another_admin_already_uploaded_is_rejected(): void
    {
        Document::factory()->for(User::factory()->admin())->create(['checksum' => hash('sha256', 'Same content.')]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/documents', ['file' => $this->textUpload('mine.txt', 'Same content.')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'This file has already been uploaded.']);

        $this->assertDatabaseCount('documents', 1);
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    public function test_non_admins_cannot_upload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/documents', ['file' => $this->textUpload('notes.txt', 'Some text.')])
            ->assertForbidden();

        $this->assertDatabaseEmpty('documents');
        $this->assertEmpty(Storage::disk('documents')->allFiles());
    }

    public function test_non_admins_receive_403_on_every_document_route_even_for_their_own_documents(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->for($user)->failed()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/documents')->assertForbidden();
        $this->getJson("/api/documents/{$document->id}")->assertForbidden();
        $this->deleteJson("/api/documents/{$document->id}")
            ->assertForbidden()
            ->assertJson(['message' => 'Only administrators can manage documents.']);
        $this->postJson("/api/documents/{$document->id}/retry")->assertForbidden();

        $this->assertModelExists($document);
        $this->assertSame(DocumentStatus::Failed, $document->fresh()->status);
    }

    public function test_index_lists_every_document_paginated_newest_first(): void
    {
        $admin = User::factory()->admin()->create();
        $own = Document::factory()->for($admin)->create();
        $anotherAdmins = Document::factory()->for(User::factory()->admin())->failed()->create();
        $withoutUploader = Document::factory()->withoutUser()->completed()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $withoutUploader->id)
            ->assertJsonPath('data.1.id', $anotherAdmins->id)
            ->assertJsonPath('data.2.id', $own->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonMissingPath('data.0.storage_path');
    }

    public function test_admin_can_view_another_admins_document_without_leaking_internal_error_details(): void
    {
        $document = Document::factory()->for(User::factory()->admin())->failed()->create([
            'error_message' => 'fopen(/var/www/storage/app/documents/x.txt): Failed to open stream',
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson("/api/documents/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Processing failed. The document can be retried.')
            ->assertJsonMissingPath('data.storage_path');
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringNotContainsString($document->storage_path, $response->getContent());
    }

    public function test_viewing_a_missing_document_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/documents/999')->assertNotFound();
    }

    public function test_admin_deleting_another_admins_document_removes_the_file_and_chunks(): void
    {
        $document = Document::factory()->for(User::factory()->admin())->create(['storage_path' => 'stored.txt']);
        DocumentChunk::factory()->for($document)->create();
        Storage::disk('documents')->put('stored.txt', 'contents');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/documents/{$document->id}")->assertNoContent();

        $this->assertModelMissing($document);
        $this->assertDatabaseEmpty('document_chunks');
        Storage::disk('documents')->assertMissing('stored.txt');
    }

    public function test_non_admin_cannot_delete_another_users_document(): void
    {
        $document = Document::factory()->for(User::factory()->admin())->create(['storage_path' => 'theirs.txt']);
        Storage::disk('documents')->put('theirs.txt', 'contents');
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();

        $this->assertModelExists($document);
        Storage::disk('documents')->assertExists('theirs.txt');
    }

    public function test_admin_retrying_another_admins_failed_document_moves_it_to_pending(): void
    {
        $document = Document::factory()->for(User::factory()->admin())->failed()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/documents/{$document->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.error', null);

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'status' => 'pending', 'error_message' => null]);
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{DocumentStatus}>
     */
    public static function nonFailedStatuses(): array
    {
        return [
            'pending' => [DocumentStatus::Pending],
            'processing' => [DocumentStatus::Processing],
            'completed' => [DocumentStatus::Completed],
        ];
    }

    #[DataProvider('nonFailedStatuses')]
    public function test_only_failed_documents_can_be_retried(DocumentStatus $status): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->for($admin)->create(['status' => $status]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/documents/{$document->id}/retry")
            ->assertConflict()
            ->assertJson(['message' => 'Only failed documents can be retried.']);

        $this->assertSame($status, $document->fresh()->status);
    }

    /**
     * Build an upload backed by a real file, so its MIME type is sniffed from the bytes.
     *
     * UploadedFile::fake() reports a MIME type derived from the extension, which
     * would bypass the content check under test.
     */
    private function textUpload(string $clientName, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }
}
