<?php

namespace App\Billing;

/**
 * A cost estimate before submission. All amounts are nanodollars.
 *
 * "typical" uses the expected token counts; "max" the most Pixelforge can bill for these inputs.
 * We store the max on the generation and show both, so a user is never charged more than they saw.
 */
final readonly class Estimate
{
    /**
     * @param  array<string, int>  $typicalTokens
     * @param  array<string, int>  $maxTokens
     */
    public function __construct(
        public array $typicalTokens,
        public array $maxTokens,
        public int $typicalProviderCost,
        public int $maxProviderCost,
    ) {}

    public function typicalCharge(): int
    {
        return Nanodollars::withMarkup($this->typicalProviderCost);
    }

    public function maxCharge(): int
    {
        return Nanodollars::withMarkup($this->maxProviderCost);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'typical_charge' => $this->typicalCharge(),
            'max_charge' => $this->maxCharge(),
            'typical_charge_formatted' => Nanodollars::format($this->typicalCharge()),
            'max_charge_formatted' => Nanodollars::format($this->maxCharge()),
            'typical_tokens' => $this->typicalTokens,
            'max_tokens' => $this->maxTokens,
        ];
    }
}
