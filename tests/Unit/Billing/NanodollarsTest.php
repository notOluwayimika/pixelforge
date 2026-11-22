<?php

use App\Billing\Nanodollars;

it('parses exact decimal strings without floats', function (string $decimal, int $nanos) {
    expect(Nanodollars::fromDecimal($decimal))->toBe($nanos);
})->with([
    ['0.046125000', 46_125_000],
    ['12.50', 12_500_000_000],
    ['0', 0],
    ['0.000000001', 1],
    ['0.373875', 373_875_000],
]);

it('rejects amounts it cannot represent exactly', function (string $bad) {
    Nanodollars::fromDecimal($bad);
})->with(['0.0000000001', '-1.00', '1e-9', '', '1.'])->throws(InvalidArgumentException::class);

it('renders the nine-decimal format Pixelforge uses', function () {
    expect(Nanodollars::toDecimal(169_080_000))->toBe('0.169080000')
        ->and(Nanodollars::toDecimal(0))->toBe('0.000000000')
        ->and(Nanodollars::toDecimal(-1))->toBe('-0.000000001');
});

it('formats for humans without rounding', function () {
    expect(Nanodollars::format(46_125_000))->toBe('$0.046125')
        ->and(Nanodollars::format(1_500_000_000))->toBe('$1.50')
        ->and(Nanodollars::format(1))->toBe('$0.000000001')
        ->and(Nanodollars::format(-25_000_000))->toBe('-$0.025');
});

it('adds a 25% markup exactly', function () {
    expect(Nanodollars::withMarkup(373_875_000))->toBe(467_343_750)
        ->and(Nanodollars::withMarkup(0))->toBe(0);
});

it('rounds a fractional markup half-up to the nanodollar', function () {
    // 1 × 1.25 = 1.25 -> 1;  2 × 1.25 = 2.5 -> 3;  3 × 1.25 = 3.75 -> 4
    expect(Nanodollars::withMarkup(1))->toBe(1)
        ->and(Nanodollars::withMarkup(2))->toBe(3)
        ->and(Nanodollars::withMarkup(3))->toBe(4);
});
