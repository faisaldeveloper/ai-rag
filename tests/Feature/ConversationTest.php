<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_conversation_belongs_to_a_user_and_has_messages_in_order(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $question = Message::factory()->for($conversation)->create();
        $answer = Message::factory()->assistant()->for($conversation)->create();

        $this->assertTrue($conversation->user->is($user));
        $this->assertTrue($user->conversations->first()->is($conversation));
        $this->assertSame([$question->id, $answer->id], $conversation->messages->pluck('id')->all());
        $this->assertTrue($answer->conversation->is($conversation));
    }

    public function test_messages_have_metadata_and_only_a_created_at_timestamp(): void
    {
        $message = Message::factory()->create(['metadata' => ['citations' => [1, 2]]])->fresh();

        $this->assertSame(['citations' => [1, 2]], $message->metadata);
        $this->assertNotNull($message->created_at);
        $this->assertFalse(Schema::hasColumn('messages', 'updated_at'));
    }

    public function test_deleting_a_conversation_deletes_its_messages(): void
    {
        $conversation = Conversation::factory()->create();
        Message::factory()->for($conversation)->count(2)->create();
        $otherMessage = Message::factory()->create();

        $conversation->delete();

        $this->assertDatabaseCount('messages', 1);
        $this->assertModelExists($otherMessage);
    }

    public function test_deleting_a_user_deletes_their_conversations_and_messages(): void
    {
        $message = Message::factory()->create();

        $message->conversation->user->delete();

        $this->assertModelMissing($message->conversation);
        $this->assertModelMissing($message);
    }
}
