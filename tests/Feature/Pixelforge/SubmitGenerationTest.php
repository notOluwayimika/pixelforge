<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

function pixelforgeError(string $code, int $status, ?int $retryAfter = null)
{
    return Http::response(
        ['error' => ['type' => 'x', 'code' => $code, 'message' => "Pixelforge says {$code}."]],
        $status,
        $retryAfter ? ['Retry-After' => (string) $retryAfter] : [],
    );
}

/** Run the job the way a worker would, with a fake queue job so release() is observable. */
function runSubmit(Generation $generation): Job
{
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $queueJob->shouldReceive('attempts')->andReturn(1);

    $job = new SubmitGeneration($generation->id);
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);

    return $queueJob;
}

it('submits with our idempotency key and metadata, then stores the provider id', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(providerGeneration('queued'), 202)]);

    runSubmit($generation);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        && $request['metadata'] === ['generation_id' => $generation->id]
        && $request['quality'] === 'high'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBe('gen_test_1')
        ->rates->toEqual(providerGeneration('queued')['rates'])
        ->submitted_at->not->toBeNull();
});

it('waits for a free slot when the concurrency limit is hit', function (string $code, int $status, int $retryAfter) {
    $generation = Generation::factory()->create();
    Http::fake(['*' => pixelforgeError($code, $status, $retryAfter)]);

    $queueJob = runSubmit($generation);

    $queueJob->shouldHaveReceived('release')->withArgs(fn (int $delay) => $delay >= $retryAfter && $delay <= $retryAfter + 2);
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Submitting)
        ->submit_attempts->toBe(1);
})->with([
    'concurrency limit' => ['concurrency_limit_exceeded', 429, 5],
    'rate limit' => ['rate_limit_exceeded', 429, 12],
    'overloaded' => ['overloaded', 503, 2],
]);

it('fails without charging when Pixelforge rejects the request outright', function (string $code, int $status) {
    $generation = Generation::factory()->create();
    Http::fake(['*' => pixelforgeError($code, $status)]);

    $queueJob = runSubmit($generation);

    $queueJob->shouldNotHaveReceived('release');
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->charge->toBe(0);
})->with([
    'content policy' => ['content_policy_violation', 400],
    'daily quota' => ['daily_limit_exceeded', 429],
    'validation' => ['validation_failed', 422],
]);

it('does not resubmit a generation that a webhook already moved on', function () {
    $generation = Generation::factory()->accepted()->create(['status' => GenerationStatus::Processing]);
    Http::fake();

    runSubmit($generation);

    Http::assertNothingSent();
});

it('keeps the webhook state when the POST response arrives after it', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => function () use ($generation) {
        // Simulate Pixelforge's webhook landing while our POST is still in flight.
        Generation::whereKey($generation->id)->update(['status' => GenerationStatus::Processing]);

        return Http::response(providerGeneration('queued'), 202);
    }]);

    runSubmit($generation);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('tells the user and charges nothing once it gives up retrying', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation->id))->failed(new RuntimeException('timed out'));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_gave_up')
        ->charge->toBe(0);
});
