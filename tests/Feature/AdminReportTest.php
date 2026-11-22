<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Ops']);
    $this->admin->forceFill(['is_admin' => true])->save();
});

/** A completed generation with the given money (nanodollars) at a UTC moment. */
function completedGeneration(User $user, string $completedAt, int $providerCost, string $status = 'succeeded'): Generation
{
    $failed = $status === 'failed';

    return Generation::factory()->for($user)->accepted('gen_'.fake()->unique()->bothify('????????'))->create([
        'status' => GenerationStatus::from($status),
        'provider_cost' => $failed ? 0 : $providerCost,
        'charge' => $failed ? 0 : intdiv($providerCost * 5 + 2, 4),
        'completed_at' => $completedAt,
    ]);
}

/** Fake GET /v1/usage returning the given cost per day. */
function fakeUsage(array $days): void
{
    Http::fake(['*/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => collect($days)->map(fn (array $day, string $date) => [
            'date' => $date,
            'generations' => $day[0],
            'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0,
            'cost_usd' => $day[1],
        ])->values()->all(),
        'total' => [],
    ])]);
}

it('is only for admins', function () {
    $this->get('/admin/report')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
});

it('cannot be granted through the profile form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Sneaky', 'email' => $user->email, 'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('groups by user and UTC completion day, with exact totals and profit', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    completedGeneration($alice, '2026-11-20 10:00:00', 305_510_000);
    completedGeneration($alice, '2026-11-20 23:59:59', 15_752_500);
    completedGeneration($bob, '2026-11-20 12:00:00', 62_897_500);
    completedGeneration($bob, '2026-11-20 13:00:00', 0, status: 'failed');
    // Created on the 20th but completed just after midnight UTC: billed on the 21st.
    completedGeneration($bob, '2026-11-21 00:00:01', 55_082_500);
    // Still processing: not billed by anyone yet, so not in the report.
    Generation::factory()->for($alice)->accepted('gen_inflight')->create(['status' => GenerationStatus::Processing]);

    fakeUsage(['2026-11-20' => [3, '0.384160000'], '2026-11-21' => [1, '0.055082500']]);

    $this->actingAs($this->admin)->get('/admin/report?start=2026-11-20&end=2026-11-21')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/UsageReport')
            ->where('totals.generations', 4)
            ->where('totals.failed', 1)
            ->where('totals.provider_cost', '0.439242500')
            ->where('totals.charged', '0.549053125')
            ->where('totals.profit', '0.109810625')
            ->where('days.0.date', '2026-11-21')
            ->where('days.1.date', '2026-11-20')
            ->where('days.1.generations', 3)
            ->where('days.1.provider_cost', '0.384160000')
            ->where('days.1.pixelforge.matches', true)
            ->where('days.1.users.0.name', 'Alice') // highest spend first
            ->where('days.1.users.0.charged', '0.401578125') // 0.3818875 + 0.019690625
            ->where('days.1.users.1.failed', 1)
            ->where('days.0.pixelforge.matches', true)
            ->where('pixelforge_error', null));
});

it('flags a day that does not match Pixelforge', function () {
    completedGeneration($this->admin, '2026-11-20 10:00:00', 305_510_000);
    fakeUsage(['2026-11-20' => [2, '0.321262500']]); // Pixelforge billed one we never recorded

    $this->actingAs($this->admin)->get('/admin/report?start=2026-11-20&end=2026-11-20')
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.pixelforge.matches', false)
            ->where('days.0.pixelforge.provider_cost', '0.321262500'));
});

it('still renders when Pixelforge is unreachable', function () {
    completedGeneration($this->admin, '2026-11-20 10:00:00', 305_510_000);
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Overloaded']], 503)]);

    $this->actingAs($this->admin)->get('/admin/report?start=2026-11-20&end=2026-11-20')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.provider_cost', '0.305510000')
            ->where('days.0.pixelforge', null)
            ->where('pixelforge_error', fn (string $error) => str_contains($error, 'Overloaded')));
});

it('refuses ranges longer than Pixelforge allows', function () {
    Http::fake();

    $this->actingAs($this->admin)->get('/admin/report?start=2026-01-01&end=2026-11-20')
        ->assertSessionHasErrors('start');

    Http::assertNothingSent();
});

it('grants and revokes admin from the command line', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('app:make-admin ops@example.com')->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('app:make-admin ops@example.com --revoke')->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeFalse();

    $this->artisan('app:make-admin nobody@example.com')->assertFailed();
});
