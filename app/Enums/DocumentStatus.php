<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Determine whether a document in this status may move to the given status.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Processing],
            self::Processing => [self::Completed, self::Failed],
            self::Completed => [],
            self::Failed => [self::Pending],
        }, true);
    }
}
