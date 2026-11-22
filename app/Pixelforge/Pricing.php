<?php

namespace App\Pixelforge;

/**
 * The GET /v1/pricing response: current rates plus the token tables used for estimates.
 * Nothing here is hardcoded; sizes and qualities are whatever Pixelforge publishes.
 */
final readonly class Pricing
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => exact tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param  array<string, mixed>  $body */
    public static function fromApi(array $body): self
    {
        $tokens = $body['tokens'];

        return new self(
            rates: Rates::fromApi($body['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // 0.15 => 1500 basis points, so the estimate maths below stays in integers.
            textTokenVarianceBasisPoints: (int) round($tokens['text_token_variance'] * 10_000),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys(array_values($this->outputTokens)[0] ?? []);
    }
}
