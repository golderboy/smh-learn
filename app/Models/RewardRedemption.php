<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $received_at
 * @property Carbon|null $expires_at
 */
class RewardRedemption extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['coupon_code', 'coupon_hash', 'request_key', 'receive_request_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['coupon_code' => 'encrypted', 'discount_satang' => 'integer', 'points_used' => 'integer', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'received_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return HasOne<WelfareClaimItem, $this> */
    public function claimItem(): HasOne
    {
        return $this->hasOne(WelfareClaimItem::class);
    }
}
