<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_receive_401(): void
    {
        $conversation = Conversation::factory()->create();

        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->getJson("/api/conversations/{$conversation->id}")->assertUnauthorized();
    }

    public function test_a_user_lists_only_their_own_conversations_most_recent_first(): void
    {
        $user = User::factory()->create();
        $older = Conversation::factory()->for($user)->create(['updated_at' => now()->subDay()]);
        $newer = Conversation::factory()->for($user)->create(['updated_at' => now()]);
        Conversation::factory()->create();
        Conversation::factory()->for(User::factory()->admin())->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/conversations');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
        $this->assertArrayNotHasKey('messages', $response->json('data.0'));
    }

    public function test_a_new_message_moves_its_conversation_to_the_top(): void
    {
        $user = User::factory()->create();
        $first = Conversation::factory()->for($user)->create(['updated_at' => now()->subDay()]);
        Conversation::factory()->for($user)->create(['updated_at' => now()->subHour()]);
        Message::factory()->for($first)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/conversations')->assertJsonPath('data.0.id', $first->id);
    }

    public function test_a_user_sees_their_conversation_with_its_messages_and_citations_but_no_internal_metadata(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $question = Message::factory()->for($conversation)->create();
        $citation = ['label' => 'S1', 'document_id' => 1, 'document_title' => 'Refund Policy', 'heading' => null, 'page' => null, 'chunk_id' => 9, 'score' => 0.9];
        $answer = Message::factory()->assistant()->for($conversation)->create([
            'metadata' => ['citations' => [$citation], 'provider' => 'gemini', 'model' => 'SECRET-MODEL', 'retrieved' => [['chunk_id' => 9, 'score' => 0.9]], 'latency_ms' => 12],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/conversations/{$conversation->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.messages.0.id', $question->id)
            ->assertJsonPath('data.messages.0.citations', [])
            ->assertJsonPath('data.messages.1.id', $answer->id)
            ->assertJsonPath('data.messages.1.content', $answer->content)
            ->assertJsonPath('data.messages.1.citations', [$citation]);
        $this->assertStringNotContainsString('SECRET-MODEL', $response->getContent());
        $this->assertStringNotContainsString('latency_ms', $response->getContent());
    }

    public function test_messages_say_whether_they_are_the_fallback_answer(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        Message::factory()->for($conversation)->create();
        Message::factory()->assistant()->for($conversation)->create(['metadata' => ['fallback' => true, 'citations' => []]]);
        Message::factory()->assistant()->for($conversation)->create(['metadata' => ['fallback' => false, 'citations' => []]]);
        Sanctum::actingAs($user);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.fallback', false)
            ->assertJsonPath('data.messages.1.fallback', true)
            ->assertJsonPath('data.messages.2.fallback', false);
    }

    public function test_another_users_conversation_is_not_found_even_for_an_admin(): void
    {
        $conversation = Conversation::factory()->create();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/conversations/{$conversation->id}")->assertNotFound();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/conversations/{$conversation->id}")->assertNotFound();
    }
}
