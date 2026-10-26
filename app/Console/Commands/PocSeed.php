<?php

namespace App\Console\Commands;

use App\Models\PocArticle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('poc:seed')]
#[Description('Store three sample articles with generated embeddings (vector search proof of concept)')]
class PocSeed extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $articles = [
            [
                'title' => 'Cancelling your subscription',
                'body' => 'You can cancel your subscription at any time from Settings > Billing > Cancel subscription. Your plan stays active until the end of the current billing period and you will not be charged again after that.',
            ],
            [
                'title' => 'Refund eligibility',
                'body' => 'Refunds are available within 14 days of a payment if the service has not been substantially used. Annual plans cancelled after 14 days are not refunded, but you keep access until the period ends.',
            ],
            [
                'title' => 'Changing your account password',
                'body' => 'To change your password, go to Settings > Security > Change password, enter your current password, then choose a new one of at least 12 characters. You will be signed out of other devices.',
            ],
        ];

        $embeddings = PocArticle::embed(array_map(
            fn (array $article): string => $article['title']."\n\n".$article['body'],
            $articles,
        ));

        foreach ($articles as $index => $article) {
            PocArticle::updateOrCreate(
                ['title' => $article['title']],
                ['body' => $article['body'], 'embedding' => $embeddings[$index]],
            );
        }

        $this->info(sprintf('Stored %d articles with %d-dimension embeddings.', count($articles), config('rag.embedding_dimensions')));

        return self::SUCCESS;
    }
}
