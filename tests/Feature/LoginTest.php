<?php

namespace Tests\Feature;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_see_the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('auth.user', null));
    }

    public function test_valid_credentials_sign_the_user_in_and_redirect_to_chat(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_and_an_unknown_email_get_the_same_error(): void
    {
        $user = User::factory()->create();

        $wrongPassword = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $unknownEmail = $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'password']);

        $wrongPassword->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
        $unknownEmail->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_the_email_and_password_are_required(): void
    {
        $this->from('/login')->post('/login', [])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    public function test_logins_are_locked_after_too_many_failures_even_with_the_right_password(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < LoginRequest::MaxAttempts; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $response = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_a_lockout_on_one_account_does_not_lock_another(): void
    {
        $locked = User::factory()->create();
        $other = User::factory()->create();

        for ($attempt = 0; $attempt < LoginRequest::MaxAttempts; $attempt++) {
            $this->post('/login', ['email' => $locked->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($other);
    }

    public function test_signed_in_users_are_sent_from_the_login_page_to_chat(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(route('chat.index'));
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_guests_cannot_sign_out(): void
    {
        $this->post('/logout')->assertRedirect(route('login'));
    }
}
