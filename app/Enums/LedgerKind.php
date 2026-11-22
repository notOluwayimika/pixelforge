<?php

namespace App\Enums;

/**
 * Life of one generation in the ledger (amounts in nanodollars):
 *
 *   submit       HOLD     −max estimate      (balance can't drop below 0)
 *   succeeded    RELEASE  +max estimate
 *                CHARGE   −actual charge     (≤ max, so the user gets the difference back)
 *   failed       RELEASE  +max estimate      (failures are free)
 */
enum LedgerKind: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Bought credits',
            self::Hold => 'Reserved (max estimate)',
            self::Release => 'Reservation released',
            self::Charge => 'Charged',
        };
    }
}
