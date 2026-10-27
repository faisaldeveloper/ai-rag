<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

abstract class TestCase extends BaseTestCase
{
    /**
     * Send every log record through a fresh in-memory handler and return it.
     *
     * Unlike Log::spy(), the records include everything a real channel writes:
     * the message, the context, and the extra data added by processors.
     */
    protected function captureLogs(): TestHandler
    {
        config([
            'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.default' => 'capture',
        ]);
        Log::forgetChannel('capture');

        return Log::channel('capture')->getLogger()->getHandlers()[0];
    }

    /**
     * Get everything the captured log records contain, serialized, for searching.
     */
    protected function capturedLogText(TestHandler $logs): string
    {
        return json_encode(array_map(fn ($record): array => $record->toArray(), $logs->getRecords()), JSON_THROW_ON_ERROR);
    }
}
