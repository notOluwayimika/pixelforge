<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\WebhookEvent;
use App\Pixelforge\GenerationStateMachine;
use App\Pixelforge\ProviderGeneration;
use App\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge events. Delivery is at least once and in any order, so:
 *
 * - Duplicates: the event id is inserted into webhook_events (unique) in the SAME transaction
 *   that applies the event. A second copy hits the unique constraint and is acknowledged
 *   without doing anything. If applying fails, the insert rolls back too and we return 500, so
 *   Pixelforge's retry gets a real second chance instead of being mistaken for a duplicate.
 * - Ordering: we apply the generation snapshot in data.object, and the state machine only
 *   moves forward, so a late "processing" after "succeeded" changes nothing.
 */
class PixelforgeWebhookController
{
    public function __invoke(Request $request, GenerationStateMachine $stateMachine): JsonResponse
    {
        $payload = $request->getContent();

        $signature = new WebhookSignature(
            secret: (string) config('pixelforge.webhook_secret'),
            toleranceSeconds: config('pixelforge.signature_tolerance'),
        );

        if (! $signature->isValid($request->header('Pixelforge-Signature'), $payload, time())) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        $object = $event['data']['object'] ?? null;
        $isGenerationEvent = str_starts_with($event['type'], 'generation.') && is_array($object);

        try {
            DB::transaction(function () use ($event, $object, $isGenerationEvent, $request, $stateMachine) {
                WebhookEvent::create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'provider_generation_id' => $isGenerationEvent ? $object['id'] : null,
                    'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt', '1'),
                    'payload' => $event,
                    'created_at' => now(),
                ]);

                if ($isGenerationEvent) {
                    $stateMachine->applySnapshot(ProviderGeneration::fromApi($object));
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            // Only a repeat of this exact event id counts as a duplicate; any other unique
            // violation is a real error and must surface as a 500 so Pixelforge retries.
            if (! WebhookEvent::where('event_id', $event['id'])->exists()) {
                throw $e;
            }

            return response()->json(['status' => 'duplicate']);
        }

        return response()->json(['status' => 'ok']);
    }
}
