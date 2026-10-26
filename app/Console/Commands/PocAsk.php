<?php

namespace App\Console\Commands;

use App\Models\PocArticle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('poc:ask {question}')]
#[Description('Print the 3 articles most similar to a question (vector search proof of concept)')]
class PocAsk extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        [$questionEmbedding] = PocArticle::embed([$this->argument('question')]);

        $articles = PocArticle::query()
            ->select(['id', 'title'])
            ->selectVectorDistance('embedding', $questionEmbedding, as: 'distance')
            ->whereVectorSimilarTo('embedding', $questionEmbedding, minSimilarity: 0.0)
            ->limit(3)
            ->get();

        if ($articles->isEmpty()) {
            $this->warn('No articles found. Run poc:seed first.');

            return self::FAILURE;
        }

        $this->table(
            ['#', 'Title', 'Similarity'],
            $articles->map(fn (PocArticle $article, int $index): array => [
                $index + 1,
                $article->title,
                number_format(1 - $article->distance, 4),
            ]),
        );

        return self::SUCCESS;
    }
}
