<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => 'user',
            'content' => fake()->sentence().'?',
        ];
    }

    /**
     * Indicate that the message is an assistant answer.
     */
    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'assistant',
            'content' => fake()->paragraph(),
        ]);
    }
}
