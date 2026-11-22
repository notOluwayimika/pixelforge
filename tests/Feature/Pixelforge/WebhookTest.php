<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([StoreGeneratedImage::class]);
    $this->generation = Generation::factory()->accepted('gen_test_1')->create();
});

describe('signatures', function () {
    it('accepts a correctly signed event', function () {
        sendWebhook('evt_1', 'generation.processing', providerGeneration('processing'))->assertOk();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
    });

    it('rejects a bad signature and changes nothing', function () {
        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1', 'type' => 'generation.succeeded'], [
            'Pixelforge-Signature' => 't='.time().',v1=deadbeef',
        ])->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });

    it('rejects a missing signature', function () {
        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1'])->assertStatus(400);
    });

    it('rejects a replayed event signed more than 5 minutes ago', function () {
        sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'), signedAt: time() - 301)
            ->assertStatus(400);

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('rejects a body that was tampered with after signing', function () {
        $t = time();
        $signature = hash_hmac('sha256', "{$t}.".json_encode(['id' => 'evt_1']), 'whsec_test_secret');

        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$signature}",
        ], content: json_encode(['id' => 'evt_2']))->assertStatus(400);
    });
});

describe('money', function () {
    it('records the exact cost and our 25% markup on success', function () {
        sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();

        $generation = $this->generation->fresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_cost)->toBe(305_510_000) // $0.305510
            ->and($generation->charge)->toBe(381_887_500)        // $0.3818875
            ->and($generation->profit())->toBe(76_377_500);

        Queue::assertPushed(StoreGeneratedImage::class);
    });

    it('bills at the rates fixed on the generation, not whatever the event says later', function () {
        $this->generation->update(['rates' => [
            'input_text_tokens' => '5.00', 'input_image_tokens' => '10.00',
            'output_tokens' => '40.00', 'reasoning_tokens' => '12.50',
        ]]);

        sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded', [
            'rates' => ['output_tokens' => '999.00'],
        ]))->assertOk();

        expect($this->generation->fresh()->provider_cost)->toBe(305_510_000);
    });

    it('charges nothing for a failed generation', function () {
        sendWebhook('evt_1', 'generation.failed', providerGeneration('failed'))->assertOk();

        $generation = $this->generation->fresh();

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->charge)->toBe(0)
            ->and($generation->provider_cost)->toBe(0)
            ->and($generation->error_code)->toBe('internal_error');
    });
});

describe('duplicates', function () {
    it('records a duplicated event only once', function () {
        sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();
        sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        expect(WebhookEvent::count())->toBe(1)
            ->and($this->generation->fresh()->charge)->toBe(381_887_500);

        Queue::assertPushed(StoreGeneratedImage::class, 1);
    });

    it('never charges twice even if the same outcome arrives under a different event id', function () {
        sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();
        sendWebhook('evt_2', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();

        expect($this->generation->fresh()->charge)->toBe(381_887_500);
        Queue::assertPushed(StoreGeneratedImage::class, 1);
    });
});

describe('ordering', function () {
    it('ignores a late processing event after success', function () {
        sendWebhook('evt_2', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();
        sendWebhook('evt_1', 'generation.processing', providerGeneration('processing'))->assertOk();

        $generation = $this->generation->fresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charge)->toBe(381_887_500);
    });

    it('never flips a final status', function () {
        sendWebhook('evt_1', 'generation.failed', providerGeneration('failed'))->assertOk();
        sendWebhook('evt_2', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();

        expect($this->generation->fresh())
            ->status->toBe(GenerationStatus::Failed)
            ->charge->toBe(0);
    });

    it('handles a webhook that arrives before our POST has returned', function () {
        $early = Generation::factory()->create(); // still "submitting", no provider id yet

        sendWebhook('evt_1', 'generation.processing', providerGeneration('processing', [
            'id' => 'gen_early',
            'metadata' => ['generation_id' => $early->id],
        ]))->assertOk();

        expect($early->fresh())
            ->status->toBe(GenerationStatus::Processing)
            ->provider_id->toBe('gen_early');
    });
});

it('acknowledges ping and events for unknown generations', function () {
    sendWebhook('evt_ping', 'ping', [])->assertOk();
    sendWebhook('evt_x', 'generation.succeeded', providerGeneration('succeeded', ['id' => 'gen_someone_else']))->assertOk();

    expect(WebhookEvent::count())->toBe(2)
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
});
