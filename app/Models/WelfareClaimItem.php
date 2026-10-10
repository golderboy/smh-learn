<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/** @property Carbon $received_at */
class WelfareClaimItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['discount_satang' => 'integer', 'received_at' => 'datetime'];
    }

    /** @return BelongsTo<WelfareClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(WelfareClaim::class, 'welfare_claim_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Claim items are immutable.'));
        static::deleting(fn () => throw new LogicException('Claim items are immutable.'));
    }
}
