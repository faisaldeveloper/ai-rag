<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RAG\RetrievedChunk;
use App\Services\RAG\Retriever;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('rag:search {query} {--limit=10 : Number of chunks to show (at most 20)} {--user= : Email of the user to search as (defaults to the first admin)}')]
#[Description('Search the knowledge base and print every match with its score, ignoring rag.min_score')]
class RagSearch extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Retriever $retriever): int
    {
        $user = $this->searchingUser();

        if ($user === null) {
            $this->components->error('No user to search as. Pass --user=<email> or create an admin with app:create-user.');

            return self::FAILURE;
        }

        $chunks = $retriever->search($user, (string) $this->argument('query'), (int) $this->option('limit'), minScore: 0.0);

        if ($chunks->isEmpty()) {
            $this->components->warn('No chunks found.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Score', 'Document', 'Heading', 'Content'],
            $chunks->values()->map(fn (RetrievedChunk $chunk, int $index): array => [
                $index + 1,
                number_format($chunk->score, 4),
                $chunk->documentTitle,
                $chunk->heading ?? '',
                Str::substr(Str::squish($chunk->content), 0, 100),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Get the user named by --user, or the first admin when none is given.
     */
    private function searchingUser(): ?User
    {
        $email = $this->option('user');

        return $email
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_admin', true)->oldest('id')->first();
    }
}
