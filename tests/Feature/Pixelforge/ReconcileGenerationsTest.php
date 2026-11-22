<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('polls stale in-flight generations and applies what it finds', function () {
    Queue::fake([StoreGeneratedImage::class]);
    $stale = Generation::factory()->accepted('gen_test_1')->create(['updated_at' => now()->subMinutes(5)]);
    $fresh = Generation::factory()->accepted('gen_fresh')->create();

    Http::fake(['*/v1/generations/gen_test_1' => Http::response(providerGeneration('succeeded'))]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    expect($stale->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge->toBe(381_887_500)
        ->and($fresh->fresh()->status)->toBe(GenerationStatus::Queued);

    Http::assertSentCount(1);
});

it('backs off for the rest of the run when rate limited', function () {
    Generation::factory()->accepted('gen_a')->create(['updated_at' => now()->subMinutes(5)]);
    Generation::factory()->accepted('gen_b')->create(['updated_at' => now()->subMinutes(5)]);

    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => '30'])]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Http::assertSentCount(1);
});
