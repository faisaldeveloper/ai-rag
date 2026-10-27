<?php

namespace App\Console\Commands;

use App\Enums\EmbeddingTaskType;
use App\Models\User;
use App\Services\RAG\EmbeddingService;
use App\Services\RAG\Retriever;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('rag:bench-search {--file=tests/Fixtures/rag/bench-queries.txt : Queries, one per line; blank lines and lines starting with # are skipped} {--limit= : Chunks per query (defaults to rag.top_k)} {--user= : Email of the user to search as (defaults to the first admin)}')]
#[Description('Run sample queries through retrieval and report embedding, SQL, and total latency')]
class RagBenchSearch extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EmbeddingService $embeddings, Retriever $retriever): int
    {
        $path = (string) $this->option('file');
        $path = file_exists($path) ? $path : base_path($path);

        if (! is_file($path)) {
            $this->components->error("Query file [{$this->option('file')}] not found.");

            return self::FAILURE;
        }

        $queries = $this->queries($path);

        if ($queries === []) {
            $this->components->error("Query file [{$this->option('file')}] contains no queries.");

            return self::FAILURE;
        }

        $user = $this->searchingUser();

        if ($user === null) {
            $this->components->error('No user to search as. Pass --user=<email> or create an admin with app:create-user.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $rows = [];

        foreach ($queries as $index => $query) {
            $started = hrtime(true);
            $vector = $embeddings->embed([$query], EmbeddingTaskType::RetrievalQuery)[0];
            $embedded = hrtime(true);
            $chunks = $retriever->searchByVector($user, $vector, $limit);
            $finished = hrtime(true);

            $rows[] = [
                'number' => $index + 1,
                'query' => Str::limit($query, 50),
                'results' => $chunks->count(),
                'top_score' => $chunks->isEmpty() ? '-' : number_format($chunks->first()->score, 4),
                'embed_ms' => ($embedded - $started) / 1e6,
                'sql_ms' => ($finished - $embedded) / 1e6,
                'total_ms' => ($finished - $started) / 1e6,
            ];
        }

        $this->table(
            ['#', 'Query', 'Results', 'Top score', 'Embed ms', 'SQL ms', 'Total ms'],
            array_map(fn (array $row): array => [
                ...array_slice($row, 0, 4),
                $this->milliseconds($row['embed_ms']),
                $this->milliseconds($row['sql_ms']),
                $this->milliseconds($row['total_ms']),
            ], $rows),
        );

        $this->components->twoColumnDetail('Queries', (string) count($rows));
        $this->components->twoColumnDetail('Average embed latency', $this->milliseconds($this->average($rows, 'embed_ms')).' ms');
        $this->components->twoColumnDetail('Average SQL latency', $this->milliseconds($this->average($rows, 'sql_ms')).' ms');
        $this->components->twoColumnDetail('Average total latency', $this->milliseconds($this->average($rows, 'total_ms')).' ms');

        return self::SUCCESS;
    }

    /**
     * Read the queries from the file, skipping blank lines and # comments.
     *
     * @return list<string>
     */
    private function queries(string $path): array
    {
        $lines = array_map('trim', file($path, FILE_IGNORE_NEW_LINES));

        return array_values(array_filter($lines, fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
    }

    /**
     * Get the average of one latency column.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function average(array $rows, string $column): float
    {
        return array_sum(array_column($rows, $column)) / count($rows);
    }

    /**
     * Format a latency in milliseconds.
     */
    private function milliseconds(float $milliseconds): string
    {
        return number_format($milliseconds, 1);
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
