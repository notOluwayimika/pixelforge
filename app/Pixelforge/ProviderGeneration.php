<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * A Pixelforge generation object as it was at one moment: from a POST/GET response, or the
 * `data.object` of a webhook event. Webhooks and polls both funnel through this one shape.
 */
final readonly class ProviderGeneration
{
    /**
     * @param  array<string, string>  $rates
     * @param  array<string, int>|null  $usage
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $rates,
        public ?array $usage,
        public array $metadata,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /** @param  array<string, mixed>  $object */
    public static function fromApi(array $object): self
    {
        $date = fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value);

        return new self(
            id: $object['id'],
            status: GenerationStatus::from($object['status']),
            rates: $object['rates'],
            usage: $object['usage'] ?? null,
            metadata: $object['metadata'] ?? [],
            imageUrl: $object['image_url'] ?? null,
            imageUrlExpiresAt: $date($object['image_url_expires_at'] ?? null),
            errorCode: $object['error']['code'] ?? null,
            errorMessage: $object['error']['message'] ?? null,
            startedAt: $date($object['started_at'] ?? null),
            completedAt: $date($object['completed_at'] ?? null),
        );
    }

    /** Our generation id, which we send as metadata so early webhooks can still be matched. */
    public function localId(): ?string
    {
        return $this->metadata['generation_id'] ?? null;
    }
}
