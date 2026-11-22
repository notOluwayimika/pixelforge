<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationStateMachine;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one generation to Pixelforge, waiting out rate limits, the 3-at-a-time concurrency limit
 * and overloads. Safe to run any number of times: every attempt sends the row's idempotency key,
 * so Pixelforge creates (and bills) at most one generation for it.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Network errors / unexpected exceptions we tolerate before giving up (releases don't count). */
    public int $maxExceptions = 5;

    /** @var list<int> seconds between attempts after an exception (e.g. a timeout) */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly string $generationId) {}

    /** Keep waiting for a free slot for a while, then tell the user rather than retrying forever. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config('pixelforge.submit_give_up_after_minutes'));
    }

    public function handle(PixelforgeClient $pixelforge, GenerationStateMachine $stateMachine): void
    {
        $generation = Generation::find($this->generationId);

        // Already accepted (e.g. an earlier attempt's response was lost but its webhook arrived).
        if ($generation === null || $generation->status !== GenerationStatus::Submitting) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $snapshot = $pixelforge->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id],
            ], $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                $this->release($this->delayFor($e));

                return;
            }

            // content policy, validation, daily quota, key reused: retrying can't help.
            $stateMachine->rejectSubmission($generation, $e->errorCode, $e->getMessage());

            return;
        }

        // Link the provider id ourselves (we know which row we sent) rather than relying on the
        // metadata echo. "whereNull" because an early webhook may already have linked it.
        Generation::whereKey($generation->id)->whereNull('provider_id')->update(['provider_id' => $snapshot->id]);
        Generation::whereKey($generation->id)->whereNull('submitted_at')->update(['submitted_at' => now()]);

        $stateMachine->applySnapshot($snapshot);
    }

    /** Called when we run out of time or exceptions. Pixelforge never accepted it, so it's free. */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation !== null) {
            app(GenerationStateMachine::class)->rejectSubmission(
                $generation,
                'submission_gave_up',
                'Pixelforge was too busy to accept this generation, so we stopped trying. You were not charged; please retry.',
            );
        }
    }

    /**
     * Honour Retry-After, and back off further on each attempt (5s, 10s, 20s ... capped at 60s),
     * plus a little jitter so queued submissions don't all retry in the same second.
     */
    private function delayFor(PixelforgeException $e): int
    {
        $base = max(1, $e->retryAfter ?? 2);
        $delay = min(60, $base * 2 ** max(0, $this->attempts() - 1));

        return $delay + random_int(0, 2);
    }
}
