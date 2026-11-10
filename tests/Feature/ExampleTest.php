<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page sends everyone to the chat, which asks guests to sign in.
     */
    public function test_the_home_page_redirects_to_the_chat(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/chat');
    }
}
