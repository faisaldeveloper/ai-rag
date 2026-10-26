<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_an_admin_and_prints_a_working_token(): void
    {
        $this->artisan('app:create-user', ['email' => 'ada@example.com', '--admin' => true])
            ->expectsQuestion('Password', 'correct-horse')
            ->expectsQuestion('Confirm password', 'correct-horse')
            ->expectsOutputToContain('Created admin [ada@example.com]')
            ->assertSuccessful();

        $user = User::where('email', 'ada@example.com')->sole();
        $this->assertTrue($user->is_admin);
        $this->assertSame('ada', $user->name);
        $this->assertTrue(Hash::check('correct-horse', $user->password));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_it_creates_a_regular_user_by_default(): void
    {
        $this->artisan('app:create-user', ['email' => 'bob@example.com', '--name' => 'Bob Smith'])
            ->expectsQuestion('Password', 'correct-horse')
            ->expectsQuestion('Confirm password', 'correct-horse')
            ->expectsOutputToContain('Created user [bob@example.com]')
            ->assertSuccessful();

        $user = User::where('email', 'bob@example.com')->sole();
        $this->assertFalse($user->is_admin);
        $this->assertSame('Bob Smith', $user->name);
    }

    public function test_it_fails_when_the_passwords_do_not_match(): void
    {
        $this->artisan('app:create-user', ['email' => 'ada@example.com'])
            ->expectsQuestion('Password', 'correct-horse')
            ->expectsQuestion('Confirm password', 'wrong-horse')
            ->expectsOutputToContain('The password field confirmation does not match.')
            ->assertFailed();

        $this->assertDatabaseEmpty('users');
    }

    public function test_it_fails_for_a_short_password(): void
    {
        $this->artisan('app:create-user', ['email' => 'ada@example.com'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->expectsOutputToContain('The password field must be at least 8 characters.')
            ->assertFailed();

        $this->assertDatabaseEmpty('users');
    }

    public function test_it_fails_when_the_email_is_taken(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('app:create-user', ['email' => 'ada@example.com', '--admin' => true])
            ->expectsQuestion('Password', 'correct-horse')
            ->expectsQuestion('Confirm password', 'correct-horse')
            ->expectsOutputToContain('The email has already been taken.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(User::sole()->is_admin);
    }
}
