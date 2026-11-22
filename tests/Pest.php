<?php

use App\Pixelforge\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Decoded JSON fixture from tests/Fixtures/pixelforge/{name}.json */
function pixelforgeFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/pixelforge/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

function pricingFixture(): Pricing
{
    return Pricing::fromApi(pixelforgeFixture('pricing'));
}

/**
 * A Pixelforge generation object in the given status, shaped like the docs' examples.
 * Succeeded uses the docs' usage: 12 + 765 + 6450 + 3184 tokens = $0.305510 provider cost.
 */
function providerGeneration(string $status, array $overrides = []): array
{
    $object = pixelforgeFixture('generation');
    $object['status'] = $status;

    if (in_array($status, ['processing', 'succeeded', 'failed'], true)) {
        $object['started_at'] = '2026-11-04T14:03:13Z';
    }

    if ($status === 'succeeded') {
        $object['usage'] = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ];
        $object['image_url'] = 'https://pixelforge.promptedit.dev/images/gen_test_1.png?signature=abc';
        $object['image_url_expires_at'] = now()->addDay()->toIso8601String();
        $object['completed_at'] = '2026-11-04T14:03:29Z';
    }

    if ($status === 'failed') {
        $object['error'] = ['code' => 'internal_error', 'message' => 'Something went wrong on our side while generating.'];
        $object['completed_at'] = '2026-11-04T14:03:29Z';
    }

    return array_replace_recursive($object, $overrides);
}

/** POST a correctly signed webhook event to our endpoint. */
function sendWebhook(string $eventId, string $type, array $object, ?int $signedAt = null): TestResponse
{
    $body = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => now()->toIso8601String(),
        'data' => ['object' => $object],
    ]);

    $t = $signedAt ?? time();
    $signature = hash_hmac('sha256', "{$t}.{$body}", config('pixelforge.webhook_secret'));

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$signature}",
        'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
    ], content: $body);
}
