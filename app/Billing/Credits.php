<?php

namespace App\Billing;

use App\Enums\LedgerKind;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credit balances, as an append-only ledger (see LedgerKind for the life of a generation).
 *
 * The balance is never stored; it is SUM(amount) over the user's rows, so it can't drift from the
 * history that produced it. Two rules keep it correct under concurrency:
 *
 * - Holds lock the user's row first, so two simultaneous submits can't both pass the balance
 *   check against the same money.
 * - UNIQUE(generation_id, kind) makes hold/release/charge exactly-once per generation, even if a
 *   caller runs twice.
 */
final class Credits
{
    /** The fake "Buy credits" packs, in whole dollars. */
    public const PACKS = [1, 5, 20];

    public function balance(User $user): int
    {
        return (int) LedgerEntry::where('user_id', $user->id)->sum('amount');
    }

    public function purchase(User $user, int $dollars): LedgerEntry
    {
        return LedgerEntry::create([
            'user_id' => $user->id,
            'kind' => LedgerKind::Purchase,
            'amount' => $dollars * Nanodollars::PER_DOLLAR,
            'created_at' => now(),
        ]);
    }

    /**
     * Reserve the most this generation can cost. Must run inside the transaction that creates the
     * generation, so a generation never exists without its hold (or vice versa).
     *
     * @throws InsufficientCredits
     */
    public function hold(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            // Serialise holds per user: a second concurrent submit waits here until the first
            // commits, then sees the reduced balance.
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);

            $balance = $this->balance($user);

            if ($balance < $generation->estimated_max_charge) {
                throw new InsufficientCredits($balance, $generation->estimated_max_charge);
            }

            $this->write($generation, LedgerKind::Hold, -$generation->estimated_max_charge);
        });
    }

    /**
     * Success: give back the reservation and take the actual charge. Called by the state machine
     * inside its transaction, on the one transition into "succeeded".
     */
    public function settle(Generation $generation): void
    {
        $held = $this->heldAmount($generation);

        // Generations created before balances existed have no hold, so there is nothing to settle.
        if ($held === null) {
            return;
        }

        $this->write($generation, LedgerKind::Release, $held);
        $this->write($generation, LedgerKind::Charge, -(int) $generation->charge);
    }

    /** Failure or rejection: give back the reservation. Nothing is charged. */
    public function release(Generation $generation): void
    {
        $held = $this->heldAmount($generation);

        if ($held !== null) {
            $this->write($generation, LedgerKind::Release, $held);
        }
    }

    private function heldAmount(Generation $generation): ?int
    {
        $hold = LedgerEntry::where('generation_id', $generation->id)->where('kind', LedgerKind::Hold)->value('amount');

        return $hold === null ? null : -(int) $hold;
    }

    private function write(Generation $generation, LedgerKind $kind, int $amount): void
    {
        LedgerEntry::create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'kind' => $kind,
            'amount' => $amount,
            'created_at' => now(),
        ]);
    }
}
