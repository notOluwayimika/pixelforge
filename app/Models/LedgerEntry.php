<?php

namespace App\Models;

use App\Enums\LedgerKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property LedgerKind $kind
 * @property int $amount signed nanodollars
 * @property CarbonImmutable $created_at
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => LedgerKind::class,
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
