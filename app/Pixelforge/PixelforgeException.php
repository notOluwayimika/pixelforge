<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Pixelforge error response. The docs say to branch on `code`, so the helpers below do.
 */
final class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: $error['code'] ?? 'http_'.$response->status(),
            message: $error['message'] ?? "Pixelforge returned HTTP {$response->status()}.",
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Temporary, and nothing was created: safe (and expected) to retry later with the same key.
     * The daily quota is deliberately excluded: it won't clear until UTC midnight.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true)
            || $this->status >= 500;
    }
}
