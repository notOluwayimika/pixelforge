<?php

namespace App\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of "t.body">".
 */
final readonly class WebhookSignature
{
    public function __construct(
        private string $secret,
        private int $toleranceSeconds,
    ) {}

    /**
     * @param  string  $payload  the raw request body, before any JSON decoding
     */
    public function isValid(?string $header, string $payload, int $now): bool
    {
        if ($this->secret === '' || $header === null || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        if (! ctype_digit($parts['t'] ?? '') || ($parts['v1'] ?? '') === '') {
            return false;
        }

        $timestamp = (int) $parts['t'];

        // Old (or far-future) timestamps are replays: each real attempt is freshly signed.
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        return hash_equals($expected, $parts['v1']);
    }
}
