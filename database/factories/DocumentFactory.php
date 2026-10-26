<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug(3).'.pdf';

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'original_filename' => $filename,
            'storage_path' => 'documents/'.fake()->uuid().'/'.$filename,
            'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', fake()->unique()->uuid()),
            'status' => DocumentStatus::Pending,
        ];
    }

    /**
     * Indicate that the document has no uploader.
     */
    public function withoutUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    /**
     * Indicate that the document is being processed.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Processing,
        ]);
    }

    /**
     * Indicate that the document was processed successfully.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Completed,
            'embedding_provider' => config('rag.embedding_provider'),
            'embedding_model' => config('rag.embedding_model'),
        ]);
    }

    /**
     * Indicate that processing the document failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Failed,
            'error_message' => 'Text extraction failed.',
        ]);
    }
}
