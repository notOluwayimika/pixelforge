<?php

namespace App\Billing;

use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $balance, public readonly int $required)
    {
        parent::__construct(sprintf(
            'You need up to %s for this generation but have %s. Buy credits to continue.',
            Nanodollars::format($required),
            Nanodollars::format($balance),
        ));
    }
}
