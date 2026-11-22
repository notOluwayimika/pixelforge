<?php

use App\Billing\CostEstimator;

it('reproduces the docs worked example for the most a generation can cost', function () {
    $estimate = (new CostEstimator(pricingFixture()))
        ->estimate(str_repeat('a', 400), '1536x1024', 'high', referenceImageCount: 2);

    expect($estimate->maxTokens)->toBe([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ])
        ->and($estimate->maxProviderCost)->toBe(373_875_000) // $0.373875
        ->and($estimate->maxCharge())->toBe(467_343_750);    // + 25%
});

it('uses expected token counts for the typical estimate', function () {
    $estimate = (new CostEstimator(pricingFixture()))
        ->estimate(str_repeat('a', 400), '1024x1024', 'medium', referenceImageCount: 0);

    expect($estimate->typicalTokens)->toBe([
        'input_text_tokens' => 100,
        'input_image_tokens' => 0,
        'output_tokens' => 1100,
        'reasoning_tokens' => 1100, // midpoint of 200..2000
    ]);
});

it('counts unicode characters, not bytes', function () {
    // 8 characters but 32 bytes in UTF-8
    $estimate = (new CostEstimator(pricingFixture()))->estimate('🎨🎨🎨🎨🎨🎨🎨🎨', '1024x1024', 'low', 0);

    expect($estimate->typicalTokens['input_text_tokens'])->toBe(2);
});

it('never estimates a max below the typical', function (string $quality, string $size) {
    $estimate = (new CostEstimator(pricingFixture()))->estimate('a castle', $size, $quality, 1);

    expect($estimate->maxProviderCost)->toBeGreaterThanOrEqual($estimate->typicalProviderCost);
})->with(['low', 'medium', 'high'])->with(['1024x1024', '1536x1024', '1024x1536']);
