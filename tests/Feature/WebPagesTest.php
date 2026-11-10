<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/chat')->assertRedirect(route('login'));
        $this->get('/knowledge-base')->assertRedirect(route('login'));
    }

    public function test_signed_in_users_see_the_chat_page_with_only_the_user_fields_the_layout_needs(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->actingAs($user)
            ->get('/chat')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat')
                ->where('auth.user', ['id' => $user->id, 'name' => 'Ada', 'email' => 'ada@example.com', 'is_admin' => false]));
    }

    public function test_regular_users_cannot_open_the_knowledge_base(): void
    {
        $this->actingAs(User::factory()->create())->get('/knowledge-base')->assertForbidden();
    }

    public function test_admins_see_the_knowledge_base_with_the_configured_upload_limit(): void
    {
        config(['rag.max_upload_kilobytes' => 2048]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/knowledge-base')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('KnowledgeBase')
                ->where('maxUploadKilobytes', 2048)
                ->where('auth.user.is_admin', true));
    }
}
