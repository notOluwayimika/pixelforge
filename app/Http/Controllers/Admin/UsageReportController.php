<?php

namespace App\Http\Controllers\Admin;

use App\Billing\UsageReport;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageReportController extends Controller
{
    public function __invoke(Request $request, UsageReport $report): Response|RedirectResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        // Default: the last 30 UTC days, ending today (the same default as GET /v1/usage).
        $end = isset($validated['end']) ? CarbonImmutable::parse($validated['end'], 'UTC') : CarbonImmutable::now('UTC');
        $start = isset($validated['start']) ? CarbonImmutable::parse($validated['start'], 'UTC') : $end->subDays(29);

        if ($start->diffInDays($end) + 1 > UsageReport::MAX_DAYS) {
            return back()->withErrors(['start' => 'Pick at most '.UsageReport::MAX_DAYS.' days (the Pixelforge usage limit).']);
        }

        return Inertia::render('admin/UsageReport', $report->build($start->startOfDay(), $end->startOfDay()));
    }
}
