<?php

use App\Http\Middleware\AssignRequestId;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\ProviderFailureLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (AiException|EmbeddingException|HttpClientException $exception): void {
            Log::error('Embedding provider request failed.', ProviderFailureLog::context($exception));
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
