<?php

namespace App\Billing;

use App\Pixelforge\Pricing;

/**
 * Estimates what a generation will cost, following the "How tokens are counted" rules in the
 * Pixelforge docs. Two of the four token counts are only known after completion:
 *
 * - input text: ~ceil(chars / 4), but the real count can be up to 15% either side
 * - reasoning: anywhere in a range that depends on quality
 *
 * Input image tokens (765 per reference) and output tokens (by quality × size) are exact.
 */
final readonly class CostEstimator
{
    public function __construct(private Pricing $pricing) {}

    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount): Estimate
    {
        // Unicode characters, not bytes (the docs are explicit about this).
        $characters = mb_strlen($prompt, 'UTF-8');
        $textTokens = (int) ceil($characters / $this->pricing->charactersPerTextToken);

        // ceil(textTokens × 1.15) in integer maths: 100 tokens => 115, matching the docs' worked example.
        $maxTextTokens = intdiv($textTokens * (10_000 + $this->pricing->textTokenVarianceBasisPoints) + 9_999, 10_000);

        $imageTokens = $referenceImageCount * $this->pricing->tokensPerReferenceImage;
        $outputTokens = $this->pricing->outputTokens[$quality][$size];
        $reasoning = $this->pricing->reasoningTokens[$quality];

        $typical = [
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ];

        $max = [
            ...$typical,
            'input_text_tokens' => $maxTextTokens,
            'reasoning_tokens' => $reasoning['max'],
        ];

        return new Estimate(
            typicalTokens: $typical,
            maxTokens: $max,
            typicalProviderCost: $this->pricing->rates->costOf($typical),
            maxProviderCost: $this->pricing->rates->costOf($max),
        );
    }
}
