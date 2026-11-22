<?php

namespace App\Enums;

/**
 * submitting ─► queued ─► processing ─► succeeded
 *      │           │           │
 *      └───────────┴───────────┴──────► failed
 *
 * "submitting" is ours: the row exists but Pixelforge hasn't accepted it yet (we may be waiting
 * out a rate limit). The other four are Pixelforge's statuses.
 *
 * Webhooks and polls can arrive in any order, so we never apply a transition blindly: a status
 * may only move to one with a higher rank, and succeeded/failed are final.
 */
enum GenerationStatus: string
{
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this->rank() === 3;
    }

    public function canMoveTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Submitting, self::Queued, self::Processing];
    }
}
