<?php

namespace App\Http\Controllers;

use App\Billing\Credits;
use App\Billing\Nanodollars;
use App\Models\LedgerEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    public function __construct(private readonly Credits $credits) {}

    public function index(Request $request): Response
    {
        $entries = LedgerEntry::where('user_id', $request->user()->id)
            ->with('generation:id,prompt')
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('credits/Index', [
            'packs' => Credits::PACKS,
            'entries' => $entries->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'kind' => $entry->kind->value,
                'label' => $entry->kind->label(),
                'amount' => Nanodollars::format($entry->amount),
                'prompt' => $entry->generation?->prompt,
                'created_at' => $entry->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** The fake "Buy credits" button: no payment, just a purchase row in the ledger. */
    public function purchase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pack' => ['required', 'integer', Rule::in(Credits::PACKS)],
        ]);

        $this->credits->purchase($request->user(), (int) $validated['pack']);

        return back();
    }
}
