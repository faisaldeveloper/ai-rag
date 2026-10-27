<?php

namespace App\Providers;

use App\Services\HealthCheckService;
use App\Services\RAG\DocumentTextExtractor;
use App\Services\RAG\PlainTextDocumentExtractor;
use App\Services\RAG\TextChunker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentTextExtractor::class, PlainTextDocumentExtractor::class);

        $this->app->bind(TextChunker::class, fn (): TextChunker => new TextChunker(
            config('rag.chunk_size'),
            config('rag.chunk_overlap'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::looping(fn () => HealthCheckService::recordWorkerHeartbeat());

        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute((int) config('rag.search_rate_limit'))
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
