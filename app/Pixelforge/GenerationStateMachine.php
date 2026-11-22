<?php

namespace App\Pixelforge;

use App\Billing\Credits;
use App\Billing\Nanodollars;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place a generation's status (and therefore its cost) changes.
 *
 * Webhooks, the submit job and the polling reconciler all call in here, possibly at the same
 * moment and in any order. Two rules keep that safe:
 *
 * 1. Lock the row (SELECT ... FOR UPDATE) for the whole read-decide-write, so two callers can't
 *    both see "processing" and both record a charge.
 * 2. Only move forward (GenerationStatus::canMoveTo). A late "processing" after "succeeded" is a
 *    no-op, and a final status never changes. Cost is written only on the move into succeeded,
 *    so it is recorded exactly once.
 *
 * Credits move in the same transaction as the status: settle on success, release on failure.
 * So a balance can never disagree with the generation it paid for.
 */
final class GenerationStateMachine
{
    public function __construct(private readonly Credits $credits) {}

    /**
     * Apply what Pixelforge says a generation looks like now. Returns null if it isn't ours.
     */
    public function applySnapshot(ProviderGeneration $snapshot): ?Generation
    {
        return DB::transaction(function () use ($snapshot) {
            $generation = $this->findAndLock($snapshot);

            if ($generation === null) {
                Log::warning('Pixelforge generation has no local record', ['provider_id' => $snapshot->id]);

                return null;
            }

            // A webhook can beat our own POST response, so whoever sees the provider id first stores it.
            $generation->provider_id ??= $snapshot->id;
            // Rates are fixed by Pixelforge at creation; keep the first snapshot we see.
            $generation->rates ??= $snapshot->rates;

            if ($generation->status->canMoveTo($snapshot->status)) {
                $this->moveTo($generation, $snapshot);
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Pixelforge never accepted this generation (content policy, validation, daily limit, or we gave
     * up retrying a rate limit). Nothing was created on their side, so nothing is charged.
     */
    public function rejectSubmission(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if (! $locked->status->canMoveTo(GenerationStatus::Failed)) {
                return;
            }

            $this->markFailed($locked, $code, $message, CarbonImmutable::now());
            $locked->save();
        });

        $generation->refresh();
    }

    private function findAndLock(ProviderGeneration $snapshot): ?Generation
    {
        return Generation::query()
            ->where('provider_id', $snapshot->id)
            ->when($snapshot->localId(), fn ($query, string $localId) => $query->orWhere(
                fn ($query) => $query->where('id', $localId)->whereNull('provider_id'),
            ))
            ->lockForUpdate()
            ->first();
    }

    private function moveTo(Generation $generation, ProviderGeneration $snapshot): void
    {
        $generation->status = $snapshot->status;
        $generation->started_at ??= $snapshot->startedAt;

        match ($snapshot->status) {
            GenerationStatus::Succeeded => $this->markSucceeded($generation, $snapshot),
            GenerationStatus::Failed => $this->markFailed(
                $generation,
                $snapshot->errorCode ?? 'generation_failed',
                $snapshot->errorMessage ?? 'The generation failed.',
                $snapshot->completedAt,
            ),
            default => null,
        };
    }

    private function markSucceeded(Generation $generation, ProviderGeneration $snapshot): void
    {
        $usage = $snapshot->usage ?? throw new \UnexpectedValueException("Succeeded generation {$snapshot->id} has no usage.");

        $generation->usage = $usage;
        $generation->provider_cost = Rates::fromApi($generation->rates)->costOf($usage);
        $generation->charge = Nanodollars::withMarkup($generation->provider_cost);
        $generation->provider_image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;
        $generation->completed_at = $snapshot->completedAt;

        // Release the max-estimate hold and take the actual charge.
        $this->credits->settle($generation);

        // Download the PNG before the signed URL expires (24h). Queued so webhooks stay fast,
        // and only after commit so the job never sees the pre-success row.
        StoreGeneratedImage::dispatch($generation->id)->afterCommit();
    }

    private function markFailed(Generation $generation, string $code, string $message, ?CarbonImmutable $completedAt): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->error_code = $code;
        $generation->error_message = $message;
        // Failures are free. 0, not null, so "we decided not to charge" is explicit in the data.
        $generation->provider_cost = 0;
        $generation->charge = 0;
        $generation->completed_at = $completedAt ?? CarbonImmutable::now();

        $this->credits->release($generation);
    }
}
