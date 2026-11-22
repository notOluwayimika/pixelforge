<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * All money in this app is an integer count of nanodollars: 1 = $0.000000001.
 *
 * Pixelforge reports costs as exact nine-decimal strings ("0.046125000"), so nanodollars
 * represent every provider amount exactly and reconcile digit for digit with GET /v1/usage.
 * Floats are never used: decimal strings are converted with string arithmetic.
 */
final class Nanodollars
{
    public const PER_DOLLAR = 1_000_000_000;

    /** Our price is the provider cost plus 25%. */
    public const MARKUP_NUMERATOR = 5;

    public const MARKUP_DENOMINATOR = 4;

    /**
     * Parse an exact decimal dollar string ("12.50", "0.046125000") into nanodollars.
     */
    public static function fromDecimal(string $dollars): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $dollars, $m)) {
            throw new InvalidArgumentException("Not an exact dollar amount with at most 9 decimals: [{$dollars}]");
        }

        $fraction = str_pad($m[2] ?? '', 9, '0');

        return (int) $m[1] * self::PER_DOLLAR + (int) $fraction;
    }

    /**
     * Render as a nine-decimal string, the same format Pixelforge uses ("0.046125000").
     */
    public static function toDecimal(int $nanodollars): string
    {
        $sign = $nanodollars < 0 ? '-' : '';
        $abs = abs($nanodollars);

        return $sign.intdiv($abs, self::PER_DOLLAR).'.'.str_pad((string) ($abs % self::PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Human display: exact (never rounded), trailing zeros trimmed, at least 2 decimals.
     * 46_125_000 => "$0.046125", 1_500_000_000 => "$1.50".
     */
    public static function format(int $nanodollars): string
    {
        $decimal = rtrim(self::toDecimal($nanodollars), '0');
        [$whole, $fraction] = explode('.', $decimal);
        $fraction = str_pad($fraction, 2, '0');

        return str_starts_with($whole, '-')
            ? '-$'.substr($whole, 1).'.'.$fraction
            : '$'.$whole.'.'.$fraction;
    }

    /**
     * What we charge for a given provider cost: cost × 1.25.
     *
     * Integer maths: (cost × 5 + 2) intdiv 4 rounds half-up to the nearest nanodollar.
     * At Pixelforge's current rates every cost is a multiple of 4 nanodollars, so this is exact;
     * the rounding rule only exists so that a future rate change still gives a deterministic answer.
     */
    public static function withMarkup(int $providerCost): int
    {
        return intdiv($providerCost * self::MARKUP_NUMERATOR + intdiv(self::MARKUP_DENOMINATOR, 2), self::MARKUP_DENOMINATOR);
    }
}
