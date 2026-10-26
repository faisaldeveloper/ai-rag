<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Ai\Embeddings;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'chunk_index' => fake()->unique()->numberBetween(0, 100000),
            'content' => fake()->paragraph(),
            'embedding' => Embeddings::fakeEmbedding(config('rag.embedding_dimensions')),
            'token_count' => fake()->numberBetween(50, 500),
        ];
    }
}
