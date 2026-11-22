<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationStateMachine;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Webhook delivery isn't guaranteed (Pixelforge gives up after 6 attempts). Every minute, poll
 * generations that are in flight at Pixelforge but haven't changed for a while, and push what
 * we find through the same state machine the webhooks use.
 */
#[Signature('pixelforge:reconcile {--limit=50}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks have not arrived')]
class ReconcileGenerations extends Command
{
    public function handle(PixelforgeClient $pixelforge, GenerationStateMachine $stateMachine): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds(config('pixelforge.reconcile_after_seconds')))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $snapshot = $pixelforge->getGeneration($generation->provider_id);
            } catch (PixelforgeException $e) {
                if ($e->isRetryable()) {
                    $this->warn("Pixelforge is limiting us ({$e->errorCode}); stopping until next run.");
                    break;
                }

                $this->error("{$generation->id}: {$e->errorCode}");

                continue;
            }

            $updated = $stateMachine->applySnapshot($snapshot);
            // Touch so a generation that is legitimately still processing isn't polled every run.
            $updated?->touch();

            $this->line("{$generation->id}: {$generation->status->value} -> {$updated?->status->value}");
        }

        return self::SUCCESS;
    }
}
