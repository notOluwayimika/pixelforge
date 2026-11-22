<?php

namespace App\Billing;

use App\Enums\GenerationStatus;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Per UTC day and per user: what we charged, what Pixelforge billed us, and the profit,
 * reconciled day by day against GET /v1/usage.
 *
 * Days are the UTC date a generation COMPLETED, because that's the day Pixelforge bills it on
 * (a generation created at 23:59 and finished at 00:01 belongs to the next day). The app
 * timezone is UTC, so DATE(completed_at) is that day.
 *
 * @phpstan-type ReportRow array{day: string, user_id: int, name: string, email: string, generations: int, failed: int, charged: int, provider_cost: int}
 */
final class UsageReport
{
    /** Pixelforge's limit for one usage request. */
    public const MAX_DAYS = 92;

    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ours = $this->ourRows($start, $end);
        [$theirs, $pixelforgeError] = $this->pixelforgeDays($start, $end);

        $dates = $ours->pluck('day')
            ->merge(collect($theirs)->filter(fn (array $day) => $day['generations'] > 0)->keys())
            ->unique()
            ->sortDesc()
            ->values();

        $days = $dates->map(function (string $date) use ($ours, $theirs) {
            $users = $ours->where('day', $date)->sortByDesc('charged')->values();
            $totals = $this->sum($users);
            $pixelforge = $theirs[$date] ?? null;

            return [
                'date' => $date,
                ...$this->present($totals),
                'pixelforge' => $pixelforge === null ? null : [
                    'generations' => $pixelforge['generations'],
                    'provider_cost' => Nanodollars::toDecimal($pixelforge['cost']),
                    // Same billed count and the same cost to the nanodollar.
                    'matches' => $pixelforge['generations'] === $totals['generations']
                        && $pixelforge['cost'] === $totals['provider_cost'],
                ],
                'users' => $users->map(fn (array $row) => [
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    ...$this->present($row),
                ])->all(),
            ];
        });

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'totals' => $this->present($this->sum($ours)),
            'days' => $days->all(),
            'pixelforge_error' => $pixelforgeError,
        ];
    }

    /**
     * One row per (day, user). Failed generations are counted but cost nothing.
     *
     * @return Collection<int, ReportRow>
     */
    private function ourRows(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $succeeded = GenerationStatus::Succeeded->value;
        $failed = GenerationStatus::Failed->value;

        return DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->whereIn('generations.status', [$succeeded, $failed])
            ->where('generations.completed_at', '>=', $start->startOfDay())
            ->where('generations.completed_at', '<', $end->addDay()->startOfDay())
            ->groupByRaw('DATE(generations.completed_at), users.id, users.name, users.email')
            ->selectRaw('DATE(generations.completed_at) as day, users.id as user_id, users.name, users.email')
            ->selectRaw('SUM(CASE WHEN generations.status = ? THEN 1 ELSE 0 END) as generations', [$succeeded])
            ->selectRaw('SUM(CASE WHEN generations.status = ? THEN 1 ELSE 0 END) as failed', [$failed])
            ->selectRaw('COALESCE(SUM(generations.charge), 0) as charged')
            ->selectRaw('COALESCE(SUM(generations.provider_cost), 0) as provider_cost')
            ->get()
            // Postgres returns SUM(bigint) as numeric (a PHP string): cast back to exact integers.
            ->map(fn (object $row) => [
                'day' => (string) $row->day,
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'generations' => (int) $row->generations,
                'failed' => (int) $row->failed,
                'charged' => (int) $row->charged,
                'provider_cost' => (int) $row->provider_cost,
            ]);
    }

    /**
     * Pixelforge's own numbers for this API key, keyed by date. The report still renders if
     * Pixelforge is unreachable; the error is shown instead of the match column.
     *
     * @return array{0: array<string, array{generations: int, cost: int}>, 1: string|null}
     */
    private function pixelforgeDays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            // Short cache: reloading the page shouldn't eat into the 60 requests/minute limit.
            $usage = Cache::remember(
                "pixelforge.usage.{$start->toDateString()}.{$end->toDateString()}",
                now()->addSeconds(60),
                fn () => $this->pixelforge->usage($start->toDateString(), $end->toDateString()),
            );
        } catch (PixelforgeException|ConnectionException $e) {
            return [[], "Couldn't reach Pixelforge to reconcile: {$e->getMessage()}"];
        }

        $days = [];
        foreach ($usage['data'] as $day) {
            $days[$day['date']] = [
                'generations' => (int) $day['generations'],
                'cost' => Nanodollars::fromDecimal($day['cost_usd']),
            ];
        }

        return [$days, null];
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     * @return array{generations: int, failed: int, charged: int, provider_cost: int}
     */
    private function sum(Collection $rows): array
    {
        return [
            'generations' => (int) $rows->sum('generations'),
            'failed' => (int) $rows->sum('failed'),
            'charged' => (int) $rows->sum('charged'),
            'provider_cost' => (int) $rows->sum('provider_cost'),
        ];
    }

    /**
     * Exact nine-decimal strings, the same format as /v1/usage, so the numbers compare by eye.
     *
     * @param  array{generations: int, failed: int, charged: int, provider_cost: int}  $totals
     * @return array<string, int|string>
     */
    private function present(array $totals): array
    {
        return [
            'generations' => $totals['generations'],
            'failed' => $totals['failed'],
            'charged' => Nanodollars::toDecimal($totals['charged']),
            'provider_cost' => Nanodollars::toDecimal($totals['provider_cost']),
            'profit' => Nanodollars::toDecimal($totals['charged'] - $totals['provider_cost']),
        ];
    }
}
