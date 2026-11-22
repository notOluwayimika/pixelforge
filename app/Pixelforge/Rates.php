<?php

namespace App\Pixelforge;

use App\Billing\Nanodollars;
use InvalidArgumentException;

/**
 * Pixelforge's per-token prices, as USD per 1M tokens (e.g. "12.50").
 *
 * Every generation carries its own `rates` snapshot, fixed when it was created. The actual cost
 * must use that snapshot, not the current GET /v1/pricing, because rates can change in between.
 */
final readonly class Rates
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    /**
     * @param  array<string, int>  $perMillion  nanodollars per 1M tokens, keyed by token type
     */
    private function __construct(public array $perMillion) {}

    /**
     * @param  array<string, mixed>  $rates  untrusted API data, e.g. ['output_tokens' => '40.00', ...]
     */
    public static function fromApi(array $rates): self
    {
        $perMillion = [];

        foreach (self::TOKEN_TYPES as $type) {
            if (! isset($rates[$type]) || ! is_string($rates[$type])) {
                throw new InvalidArgumentException("Pixelforge rates are missing [{$type}].");
            }

            $perMillion[$type] = Nanodollars::fromDecimal($rates[$type]);
        }

        return new self($perMillion);
    }

    /**
     * Exact cost in nanodollars of the given token counts.
     *
     * We multiply first and divide by 1M once at the end, so no precision is lost on the way.
     * At current rates the division is always exact; if a future rate made it fractional we
     * round half-up, the same rule as the markup (see Nanodollars::withMarkup).
     *
     * @param  array<string, int>  $tokens  keyed by token type; missing types count as 0
     */
    public function costOf(array $tokens): int
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $total += ($tokens[$type] ?? 0) * $this->perMillion[$type];
        }

        return intdiv($total + intdiv(self::TOKENS_PER_RATE_UNIT, 2), self::TOKENS_PER_RATE_UNIT);
    }
}
