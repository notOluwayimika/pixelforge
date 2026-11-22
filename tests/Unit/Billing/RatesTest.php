<?php

use App\Pixelforge\Rates;

$rates = fn () => Rates::fromApi([
    'input_text_tokens' => '5.00',
    'input_image_tokens' => '10.00',
    'output_tokens' => '40.00',
    'reasoning_tokens' => '12.50',
]);

it('prices the succeeded generation from the docs exactly', function () use ($rates) {
    $cost = $rates()->costOf([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ]);

    // 0.000060 + 0.007650 + 0.258000 + 0.039800
    expect($cost)->toBe(305_510_000);
});

it('matches the GET /v1/usage example to the nanodollar', function () use ($rates) {
    $cost = $rates()->costOf([
        'input_text_tokens' => 41,
        'input_image_tokens' => 0,
        'output_tokens' => 3300,
        'reasoning_tokens' => 2950,
    ]);

    expect($cost)->toBe(169_080_000); // "0.169080000"
});

it('uses the rates it was given, not current pricing', function () {
    $doubled = Rates::fromApi([
        'input_text_tokens' => '10.00',
        'input_image_tokens' => '20.00',
        'output_tokens' => '80.00',
        'reasoning_tokens' => '25.00',
    ]);

    expect($doubled->costOf(['output_tokens' => 1000]))->toBe(80_000_000);
});

it('refuses incomplete rates rather than guessing', function () {
    Rates::fromApi(['output_tokens' => '40.00']);
})->throws(InvalidArgumentException::class);
