<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageSourceApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_receive_401(): void
    {
        [$conversation, $answer] = $this->citedAnswer(Conversation::factory()->create(), $this->chunk());

        $this->getJson($this->url($conversation, $answer, 'S1'))->assertUnauthorized();
    }

    public function test_the_owner_reads_the_cited_chunk_with_its_document_title_heading_and_page(): void
    {
        $user = User::factory()->create();
        $chunk = $this->chunk(['title' => 'Refund Policy'], [
            'content' => 'Refunds are issued within 30 days.',
            'chunk_index' => 3,
            'metadata' => ['heading' => 'Policy > Refunds', 'page' => 4],
        ]);
        [$conversation, $answer] = $this->citedAnswer(Conversation::factory()->for($user)->create(), $chunk);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->url($conversation, $answer, 'S1'));

        $response->assertOk()->assertExactJson(['data' => [
            'label' => 'S1',
            'document_id' => $chunk->document_id,
            'document_title' => 'Refund Policy',
            'chunk_id' => $chunk->id,
            'chunk_index' => 3,
            'heading' => 'Policy > Refunds',
            'page' => 4,
            'content' => 'Refunds are issued within 30 days.',
        ]]);
    }

    public function test_another_users_conversation_is_not_found_even_for_an_admin(): void
    {
        [$conversation, $answer] = $this->citedAnswer(Conversation::factory()->create(), $this->chunk());

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson($this->url($conversation, $answer, 'S1'))->assertNotFound();
    }

    public function test_a_message_from_another_conversation_is_not_found(): void
    {
        $user = User::factory()->create();
        $mine = Conversation::factory()->for($user)->create();
        [, $othersAnswer] = $this->citedAnswer(Conversation::factory()->create(), $this->chunk());
        Sanctum::actingAs($user);

        $this->getJson($this->url($mine, $othersAnswer, 'S1'))->assertNotFound();
    }

    public function test_only_labels_the_answer_cites_can_be_read(): void
    {
        $user = User::factory()->create();
        $uncited = $this->chunk();
        [$conversation, $answer] = $this->citedAnswer(Conversation::factory()->for($user)->create(), $this->chunk());
        Sanctum::actingAs($user);

        $this->getJson($this->url($conversation, $answer, 'S2'))
            ->assertNotFound()
            ->assertJsonPath('message', 'The answer does not cite this source.');
        $this->getJson("/api/conversations/{$conversation->id}/messages/{$answer->id}/sources/{$uncited->id}")->assertNotFound();
    }

    public function test_a_source_whose_document_is_no_longer_searchable_is_not_found(): void
    {
        $user = User::factory()->create();
        $chunk = $this->chunk();
        [$conversation, $answer] = $this->citedAnswer(Conversation::factory()->for($user)->create(), $chunk);
        Sanctum::actingAs($user);

        $chunk->document->forceFill(['status' => DocumentStatus::Processing])->save();

        $response = $this->getJson($this->url($conversation, $answer, 'S1'));

        $response->assertNotFound()->assertJsonPath('message', 'This source is no longer in the knowledge base.');
        $this->assertStringNotContainsString($chunk->content, $response->getContent());
    }

    public function test_a_source_whose_document_was_deleted_is_not_found(): void
    {
        $user = User::factory()->create();
        $chunk = $this->chunk();
        [$conversation, $answer] = $this->citedAnswer(Conversation::factory()->for($user)->create(), $chunk);
        Sanctum::actingAs($user);

        $chunk->document->delete();

        $this->getJson($this->url($conversation, $answer, 'S1'))->assertNotFound();
    }

    /**
     * Store a chunk of a completed document.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $chunk
     */
    private function chunk(array $document = [], array $chunk = []): DocumentChunk
    {
        return DocumentChunk::factory()
            ->for(Document::factory()->completed()->create($document))
            ->create($chunk);
    }

    /**
     * Store a question and an answer citing the chunk as S1, the way ChatController stores them.
     *
     * @return array{0: Conversation, 1: Message}
     */
    private function citedAnswer(Conversation $conversation, DocumentChunk $chunk): array
    {
        Message::factory()->for($conversation)->create();
        $answer = Message::factory()->assistant()->for($conversation)->create([
            'metadata' => ['citations' => [[
                'label' => 'S1',
                'document_id' => $chunk->document_id,
                'document_title' => $chunk->document->title,
                'heading' => null,
                'page' => null,
                'chunk_id' => $chunk->id,
                'score' => 0.9,
            ]]],
        ]);

        return [$conversation, $answer];
    }

    private function url(Conversation $conversation, Message $message, string $label): string
    {
        return "/api/conversations/{$conversation->id}/messages/{$message->id}/sources/{$label}";
    }
}
