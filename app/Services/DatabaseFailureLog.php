<?php

namespace App\Services;

use App\Services\RAG\ProviderFailureLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use PDOException;
use Throwable;

/**
 * Builds the only details of a database failure that may be logged.
 *
 * A QueryException's message contains the SQL with its bound values, which
 * can include question text, chunk content, or titles, so neither the
 * message, the SQL, the bindings, nor the trace is ever logged: only the
 * exception class, the SQLSTATE, where it happened, and the IDs involved.
 */
class DatabaseFailureLog
{
    /**
     * The hidden Context key holding the class of the job being processed.
     */
    public const JobKey = 'job';

    /**
     * The hidden Context key holding the ID of the document being processed.
     */
    public const DocumentIdKey = 'document_id';

    /**
     * Get the log context for the exception, without its message, SQL, bindings, or trace.
     *
     * @return array{exception: class-string<Throwable>, sqlstate: string|null, route: string|null, job: string|null, document_id: int|null, conversation_id: int|null, request_id: string|null}
     */
    public static function context(PDOException $exception): array
    {
        $route = request()->route();

        return [
            'exception' => $exception::class,
            'sqlstate' => self::sqlState($exception),
            'route' => $route instanceof Route ? request()->method().' /'.ltrim($route->uri(), '/') : null,
            'job' => Context::getHidden(self::JobKey),
            'document_id' => self::routeModelId($route, 'document') ?? Context::getHidden(self::DocumentIdKey),
            'conversation_id' => self::routeModelId($route, 'conversation'),
            'request_id' => Context::get(ProviderFailureLog::RequestIdKey),
        ];
    }

    /**
     * Get the five-character SQLSTATE of a database exception, or null if it has none.
     */
    public static function sqlState(Throwable $exception): ?string
    {
        $state = $exception instanceof PDOException && is_array($exception->errorInfo)
            ? ($exception->errorInfo[0] ?? null)
            : $exception->getCode();

        return is_string($state) && preg_match('/^[0-9A-Z]{5}$/', $state) === 1 ? $state : null;
    }

    /**
     * Get the ID of a bound route model (or a numeric route parameter) by name.
     */
    private static function routeModelId(mixed $route, string $name): ?int
    {
        $value = $route instanceof Route ? $route->parameter($name) : null;

        return match (true) {
            $value instanceof Model => (int) $value->getKey(),
            is_numeric($value) => (int) $value,
            default => null,
        };
    }
}
