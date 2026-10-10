<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class WelfareClaim extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['selection_hash', 'request_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'coupon_count' => 'integer', 'total_satang' => 'integer'];
    }

    /** @return HasMany<WelfareClaimItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WelfareClaimItem::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Claim snapshots cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Claim snapshots cannot be deleted.'));
    }
}
