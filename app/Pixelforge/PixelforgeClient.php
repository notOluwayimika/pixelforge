<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge HTTP API. It never retries by itself: retry policy lives in
 * the queued job, where we can wait out Retry-After without holding a web request open.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}  $params
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $params, string $idempotencyKey): ProviderGeneration
    {
        $response = $this->request()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('/generations', $params);

        return ProviderGeneration::fromApi($this->ok($response)->json());
    }

    /** @throws PixelforgeException */
    public function getGeneration(string $providerId): ProviderGeneration
    {
        return ProviderGeneration::fromApi($this->ok($this->request()->get("/generations/{$providerId}"))->json());
    }

    /**
     * Current rates and token tables. Public endpoint; cached briefly because every estimate
     * needs it. Actual costs never use this: they use the rates snapshot on each generation.
     */
    public function pricing(): Pricing
    {
        $body = Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->ok(
            Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('/pricing'),
        )->json());

        return Pricing::fromApi($body);
    }

    /**
     * What Pixelforge billed this API key per UTC day (by completion date).
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->ok($this->request()->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]))->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function ok(Response $response): Response
    {
        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }
}
