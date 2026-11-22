<?php

namespace App\Models;

use App\Billing\Nanodollars;
use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property int $user_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property string $idempotency_key
 * @property string|null $provider_id
 * @property int $submit_attempts
 * @property int $estimated_typical_charge nanodollars
 * @property int $estimated_max_charge nanodollars
 * @property int|null $provider_cost nanodollars; what Pixelforge billed us
 * @property int|null $charge nanodollars; what we bill the user (provider_cost + 25%)
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_path
 * @property string|null $provider_image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inFlight());
    }

    /** The image to show: our stored copy if we have one, else Pixelforge's URL until it expires. */
    public function imageUrl(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk(config('pixelforge.image_disk'))->url($this->image_path);
        }

        if ($this->provider_image_url !== null && $this->image_url_expires_at?->isFuture()) {
            return $this->provider_image_url;
        }

        return null;
    }

    public function profit(): ?int
    {
        return $this->charge === null ? null : $this->charge - $this->provider_cost;
    }

    /** @return array<string, mixed> */
    public function toHistoryArray(): array
    {
        $money = fn (?int $nanos) => $nanos === null ? null : Nanodollars::format($nanos);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_typical_charge' => $money($this->estimated_typical_charge),
            'estimated_max_charge' => $money($this->estimated_max_charge),
            'charge' => $money($this->charge),
            'usage' => $this->usage,
            'submit_attempts' => $this->submit_attempts,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'image_url' => $this->imageUrl(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
