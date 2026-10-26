<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_admins_can_list_and_upload_documents(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->inspect($ability, Document::class)->allowed(), "Ability [{$ability}]");
            $this->assertSame(403, Gate::forUser($user)->inspect($ability, Document::class)->status(), "Ability [{$ability}]");
        }
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function uploaders(): array
    {
        return [
            'admin, own document' => [true, 'self'],
            'admin, another admin\'s document' => [true, 'other'],
            'admin, document without an uploader' => [true, 'none'],
            'non-admin, own document' => [false, 'self'],
            'non-admin, another user\'s document' => [false, 'other'],
            'non-admin, document without an uploader' => [false, 'none'],
        ];
    }

    #[DataProvider('uploaders')]
    public function test_document_access_depends_only_on_the_admin_role(bool $isAdmin, string $uploader): void
    {
        $user = $isAdmin ? User::factory()->admin()->create() : User::factory()->create();
        $document = match ($uploader) {
            'self' => Document::factory()->for($user)->create(),
            'other' => Document::factory()->for(User::factory()->admin())->create(),
            'none' => Document::factory()->withoutUser()->create(),
        };

        foreach (['view', 'delete', 'retry'] as $ability) {
            $response = Gate::forUser($user)->inspect($ability, $document);

            $this->assertSame($isAdmin, $response->allowed(), "Ability [{$ability}]");

            if (! $isAdmin) {
                $this->assertSame(403, $response->status(), "Ability [{$ability}]");
                $this->assertSame('Only administrators can manage documents.', $response->message());
            }
        }
    }
}
