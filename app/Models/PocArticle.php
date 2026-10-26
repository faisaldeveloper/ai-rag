<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Embeddings;
use RuntimeException;

#[Fillable(['title', 'body', 'embedding'])]
class PocArticle extends Model
{
    /**
     * Embed the given texts with the configured RAG provider, model, and dimensions.
     *
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     *
     * @throws RuntimeException if the provider returns the wrong number of vectors or dimensions.
     */
    public static function embed(array $texts): array
    {
        $provider = config('rag.embedding_provider');
        $model = config('rag.embedding_model');
        $dimensions = config('rag.embedding_dimensions');

        $embeddings = Embeddings::for($texts)
            ->dimensions($dimensions)
            ->generate($provider, $model)
            ->embeddings;

        if (count($embeddings) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'Expected %d embeddings but received %d (provider %s, model %s).',
                count($texts), count($embeddings), $provider, $model,
            ));
        }

        foreach ($embeddings as $index => $embedding) {
            if (count($embedding) !== $dimensions) {
                throw new RuntimeException(sprintf(
                    'Embedding %d has %d dimensions; expected %d (provider %s, model %s).',
                    $index, count($embedding), $dimensions, $provider, $model,
                ));
            }
        }

        return $embeddings;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
        ];
    }
}
