<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Jobs\ReembedDocument;
use App\Models\Document;
use App\Services\RAG\ProviderFailureLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

#[Signature('rag:reembed {--document= : ID of one completed document to re-embed} {--all : Re-embed every completed document}')]
#[Description('Queue completed documents to be re-embedded with the current embedding settings; they stay searchable meanwhile')]
class RagReembed extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Context::add(ProviderFailureLog::RequestIdKey, (string) Str::uuid());

        $documentId = $this->option('document');
        $all = (bool) $this->option('all');

        if (($documentId === null) === ! $all) {
            $this->components->error('Pass either --document=<id> or --all.');

            return self::FAILURE;
        }

        if ($all) {
            return $this->reembedAll();
        }

        $document = Document::query()->find($documentId);

        if ($document === null) {
            $this->components->error("Document [{$documentId}] does not exist.");

            return self::FAILURE;
        }

        if ($document->status !== DocumentStatus::Completed) {
            $this->components->error("Document [{$document->id}] is [{$document->status->value}]; only completed documents can be re-embedded.");

            return self::FAILURE;
        }

        ReembedDocument::dispatch($document);
        $this->components->info("Queued document [{$document->id}] for re-embedding.");

        return self::SUCCESS;
    }

    /**
     * Queue every completed document for re-embedding.
     */
    private function reembedAll(): int
    {
        $count = 0;

        Document::query()
            ->where('status', DocumentStatus::Completed)
            ->lazyById()
            ->each(function (Document $document) use (&$count): void {
                ReembedDocument::dispatch($document);
                $count++;
            });

        $this->components->info("Queued {$count} completed document(s) for re-embedding.");

        return self::SUCCESS;
    }
}
