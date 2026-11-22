<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property int $delivery_attempt
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
