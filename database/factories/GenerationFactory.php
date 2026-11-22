<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Submitting,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => [],
            'idempotency_key' => (string) Str::uuid(),
            'estimated_typical_charge' => 400_000_000,
            'estimated_max_charge' => 460_000_000,
        ];
    }

    /** Accepted by Pixelforge, as if our POST returned. */
    public function accepted(string $providerId = 'gen_test_1'): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_id' => $providerId,
            'submitted_at' => now(),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);
    }
}
