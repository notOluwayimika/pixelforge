<?php

use App\Billing\Credits;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response(pixelforgeFixture('pricing'))]);
    $this->user = User::factory()->create();
    app(Credits::class)->purchase($this->user, 20);
});

it('requires login', function () {
    $this->get('/generations')->assertRedirect('/login');
    $this->get('/generations/new')->assertRedirect('/login');
});

it('offers the sizes and qualities Pixelforge publishes', function () {
    $this->actingAs($this->user)->get('/generations/new')
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high']));
});

it('estimates the cost before submitting', function () {
    $this->actingAs($this->user)->postJson('/generations/estimate', [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ])->assertOk()->assertJson([
        'max_charge' => 467_343_750, // docs' $0.373875 maximum, plus 25%
        'max_charge_formatted' => '$0.46734375',
    ]);
});

it('creates a generation with its estimate and queues the submission', function () {
    $this->actingAs($this->user)->post('/generations', [
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => ['', '  '], // blank rows from the form are ignored
    ])->assertRedirect('/generations');

    $generation = Generation::sole();

    expect($generation)
        ->status->toBe(GenerationStatus::Submitting)
        ->reference_images->toBe([])
        ->idempotency_key->toBeString()
        ->estimated_max_charge->toBeGreaterThan($generation->estimated_typical_charge)
        ->charge->toBeNull();

    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
});

it('validates input against Pixelforge limits', function () {
    $this->actingAs($this->user)->post('/generations', [
        'prompt' => str_repeat('a', 4001),
        'size' => '512x512',
        'quality' => 'ultra',
        'reference_images' => array_fill(0, 5, 'https://example.com/a.png'),
    ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images']);

    $this->actingAs($this->user)->post('/generations', [
        'prompt' => 'ok', 'size' => '1024x1024', 'quality' => 'low',
        'reference_images' => ['ftp://example.com/a.png'],
    ])->assertSessionHasErrors('reference_images.0');

    expect(Generation::count())->toBe(0);
});

it('shows only my generations, with costs formatted exactly', function () {
    Generation::factory()->for($this->user)->accepted()->create([
        'status' => GenerationStatus::Succeeded, 'provider_cost' => 305_510_000, 'charge' => 381_887_500,
    ]);
    Generation::factory()->create(); // someone else's

    $this->actingAs($this->user)->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations', 1)
            ->where('generations.0.charge', '$0.3818875')
            ->where('hasInFlight', false));
});

it('retries a failed generation as a brand new one', function () {
    $failed = Generation::factory()->for($this->user)->accepted()->create([
        'status' => GenerationStatus::Failed, 'charge' => 0, 'provider_cost' => 0,
    ]);

    $this->actingAs($this->user)->post("/generations/{$failed->id}/retry")->assertRedirect('/generations');

    $retry = Generation::whereKeyNot($failed->id)->sole();

    expect($retry)
        ->status->toBe(GenerationStatus::Submitting)
        ->prompt->toBe($failed->prompt)
        ->idempotency_key->not->toBe($failed->idempotency_key)
        ->provider_id->toBeNull();
});

it("does not let me retry someone else's generation", function () {
    $theirs = Generation::factory()->accepted()->create(['status' => GenerationStatus::Failed]);

    $this->actingAs($this->user)->post("/generations/{$theirs->id}/retry")->assertNotFound();
});

it('only retries failed generations', function () {
    $ok = Generation::factory()->for($this->user)->accepted()->create(['status' => GenerationStatus::Succeeded]);

    $this->actingAs($this->user)->post("/generations/{$ok->id}/retry")->assertStatus(422);
});
