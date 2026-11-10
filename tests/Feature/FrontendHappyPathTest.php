<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RAG\KnowledgeBaseAgent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

/**
 * The whole journey the frontend makes, through the same routes and session
 * login it uses: an admin uploads a document, it is ingested, and a regular
 * user asks about it and opens the cited source. The AI providers are faked.
 */
class FrontendHappyPathTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const RefundText = 'Refunds are issued within 30 days of purchase.';

    private ?string $uploadPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config(['rag.min_score' => 0.5]);

        $vector = array_pad([1.0], config('rag.embedding_dimensions'), 0.0);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $vector, $prompt->inputs));
        KnowledgeBaseAgent::fake(['Refunds are issued within 30 days [S1].']);

        // Requests from the app's own origin are authenticated by the session, like the browser's.
        $this->withHeader('Referer', config('app.url'));
    }

    protected function tearDown(): void
    {
        if ($this->uploadPath !== null) {
            @unlink($this->uploadPath);
        }

        parent::tearDown();
    }

    public function test_an_admin_uploads_a_document_and_a_user_gets_a_cited_answer_from_it(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('chat.index'));
        $this->get('/knowledge-base')->assertInertia(fn (Assert $page) => $page->component('KnowledgeBase')->etc());

        $documentId = $this->postJson('/api/documents', ['file' => $this->upload('refunds.md', "# Refunds\n\n".self::RefundText)])
            ->assertCreated()
            ->json('data.id');
        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $documentId)
            ->assertJsonPath('data.0.status', 'completed');

        $this->post('/logout')->assertRedirect(route('login'));
        // Each browser request gets a fresh app; here one app serves the whole test, so reset what auth:sanctum cached.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($user);
        $this->get('/chat')->assertInertia(fn (Assert $page) => $page->component('Chat')->where('auth.user.id', $user->id));

        $reply = $this->postJson('/api/chat', ['message' => 'How long do refunds take?'])
            ->assertCreated()
            ->assertJsonPath('data.fallback', false)
            ->assertJsonPath('data.assistant_message.content', 'Refunds are issued within 30 days [S1].')
            ->assertJsonPath('data.assistant_message.citations.0.label', 'S1')
            ->json('data');

        $this->getJson('/api/conversations')->assertJsonPath('data.0.id', $reply['conversation_id']);
        $this->getJson("/api/conversations/{$reply['conversation_id']}")->assertJsonCount(2, 'data.messages');

        $this->getJson("/api/conversations/{$reply['conversation_id']}/messages/{$reply['assistant_message']['id']}/sources/S1")
            ->assertOk()
            ->assertJsonPath('data.document_id', $documentId)
            ->assertJsonPath('data.heading', 'Refunds')
            ->assertJsonPath('data.content', fn (string $content): bool => str_contains($content, self::RefundText));
    }

    /**
     * Build an upload from a real file, so the server's content checks see real text.
     */
    private function upload(string $clientName, string $contents): UploadedFile
    {
        $this->uploadPath = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($this->uploadPath, $contents);

        return new UploadedFile($this->uploadPath, $clientName, null, null, true);
    }
}
