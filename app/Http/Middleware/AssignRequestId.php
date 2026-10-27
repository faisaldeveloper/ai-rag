<?php

namespace App\Http\Middleware;

use App\Services\RAG\ProviderFailureLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID, added to the log Context and the X-Request-Id response header.
 *
 * The ID is always generated, never taken from the client, so it cannot be
 * spoofed or used to inject text into logs. Context is carried into queued
 * jobs, so jobs dispatched by the request log the same ID.
 */
class AssignRequestId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        Context::add(ProviderFailureLog::RequestIdKey, $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
