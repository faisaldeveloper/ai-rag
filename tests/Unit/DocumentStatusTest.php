<?php

namespace Tests\Unit;

use App\Enums\DocumentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentStatusTest extends TestCase
{
    /**
     * @return array<string, array{DocumentStatus, DocumentStatus, bool}>
     */
    public static function transitions(): array
    {
        $allowed = [
            'pending->processing',
            'processing->completed',
            'processing->failed',
            'failed->pending',
        ];

        $cases = [];

        foreach (DocumentStatus::cases() as $from) {
            foreach (DocumentStatus::cases() as $to) {
                $name = "{$from->value}->{$to->value}";
                $cases[$name] = [$from, $to, in_array($name, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_only_the_documented_transitions_are_allowed(DocumentStatus $from, DocumentStatus $to, bool $expected): void
    {
        $this->assertSame($expected, $from->canTransitionTo($to));
    }
}
