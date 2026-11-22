<?php

use App\Billing\Credits;
use App\Enums\GenerationStatus;
use App\Enums\LedgerKind;
use App\Jobs\StoreGeneratedImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Pixelforge\GenerationStateMachine;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SubmitGeneration::class, StoreGeneratedImage::class]);
    Http::fake(['*/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    $this->user = User::factory()->create();
    $this->credits = app(Credits::class);
});

/** The docs' max-cost generation: high 1536x1024, 400 chars, 2 refs => max charge $0.46734375. */
function maxCostRequest(): array
{
    return [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];
}

function ledgerKinds(Generation $generation): array
{
    return LedgerEntry::where('generation_id', $generation->id)->orderBy('id')->pluck('amount', 'kind')->all();
}

it('buys credits in exact dollar packs only', function () {
    $this->actingAs($this->user)->post('/credits/purchase', ['pack' => 5])->assertRedirect();
    $this->actingAs($this->user)->post('/credits/purchase', ['pack' => 7])->assertSessionHasErrors('pack');

    expect($this->credits->balance($this->user))->toBe(5_000_000_000);
});

it('blocks a generation the user cannot afford, creating nothing', function () {
    $this->actingAs($this->user)->post('/generations', maxCostRequest())
        ->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('blocks on the maximum estimate, not the typical one', function () {
    // $0.45: more than the typical estimate (~$0.40) but less than the max ($0.46734375).
    LedgerEntry::create(['user_id' => $this->user->id, 'kind' => LedgerKind::Purchase, 'amount' => 450_000_000, 'created_at' => now()]);

    $this->actingAs($this->user)->post('/generations', maxCostRequest())->assertSessionHasErrors('credits');
});

it('reserves the maximum estimate when a generation is submitted', function () {
    $this->credits->purchase($this->user, 1);

    $this->actingAs($this->user)->post('/generations', maxCostRequest())->assertRedirect('/generations');

    $generation = Generation::sole();
    expect(ledgerKinds($generation))->toBe(['hold' => -467_343_750])
        ->and($this->credits->balance($this->user))->toBe(1_000_000_000 - 467_343_750);
    Queue::assertPushed(SubmitGeneration::class);
});

it('counts reservations, so in-flight generations cannot overspend', function () {
    $this->credits->purchase($this->user, 1); // covers two max-cost generations, not three

    $this->actingAs($this->user)->post('/generations', maxCostRequest())->assertRedirect('/generations');
    $this->actingAs($this->user)->post('/generations', maxCostRequest())->assertRedirect('/generations');
    $this->actingAs($this->user)->post('/generations', maxCostRequest())->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(2)
        ->and($this->credits->balance($this->user))->toBe(1_000_000_000 - 2 * 467_343_750);
});

/** Submit for real (hold placed), then mark it accepted as if Pixelforge returned. */
function submittedGeneration(User $user): Generation
{
    test()->actingAs($user)->post('/generations', [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.png'],
    ])->assertRedirect('/generations');

    $generation = Generation::latest()->first();
    $generation->update(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_test_1', 'rates' => pixelforgeFixture('pricing')['rates']]);

    return $generation;
}

it('settles to the actual charge on success, exactly once', function () {
    $this->credits->purchase($this->user, 1);
    $generation = submittedGeneration($this->user);
    $held = $generation->estimated_max_charge;

    sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'))->assertOk();
    sendWebhook('evt_1', 'generation.succeeded', providerGeneration('succeeded'))->assertOk(); // duplicate
    sendWebhook('evt_2', 'generation.succeeded', providerGeneration('succeeded'))->assertOk(); // same outcome, new id

    expect(ledgerKinds($generation))->toBe(['hold' => -$held, 'release' => $held, 'charge' => -381_887_500])
        ->and($this->credits->balance($this->user))->toBe(1_000_000_000 - 381_887_500);
});

it('releases the reservation when a generation fails', function () {
    $this->credits->purchase($this->user, 1);
    $generation = submittedGeneration($this->user);

    sendWebhook('evt_1', 'generation.failed', providerGeneration('failed'))->assertOk();

    expect(LedgerEntry::where('generation_id', $generation->id)->where('kind', 'charge')->exists())->toBeFalse()
        ->and($this->credits->balance($this->user))->toBe(1_000_000_000);
});

it('releases the reservation when Pixelforge rejects the submission', function () {
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'No.']], 400)]);
    $this->credits->purchase($this->user, 1);
    $this->actingAs($this->user)->post('/generations', maxCostRequest());

    (new SubmitGeneration(Generation::sole()->id))->handle(app(PixelforgeClient::class), app(GenerationStateMachine::class));

    expect(Generation::sole()->status)->toBe(GenerationStatus::Failed)
        ->and($this->credits->balance($this->user))->toBe(1_000_000_000);
});

it('refuses a second settlement at the database level', function () {
    $this->credits->purchase($this->user, 1);
    $generation = submittedGeneration($this->user);
    $generation->update(['charge' => 381_887_500]);

    $this->credits->settle($generation);
    $this->credits->settle($generation);
})->throws(UniqueConstraintViolationException::class);

it('needs credits to retry a failed generation', function () {
    $failed = Generation::factory()->for($this->user)->accepted()->create(['status' => GenerationStatus::Failed]);

    $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(1);
});

it('shows the balance and the ledger', function () {
    $this->credits->purchase($this->user, 20);

    $this->actingAs($this->user)->get('/credits')
        ->assertInertia(fn ($page) => $page
            ->component('credits/Index')
            ->where('credits.formatted', '$20.00')
            ->where('entries.0.kind', 'purchase')
            ->where('entries.0.amount', '$20.00'));
});
