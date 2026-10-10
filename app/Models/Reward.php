<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 */
class Reward extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'required_points' => 'integer', 'inventory' => 'integer', 'discount_satang' => 'integer', 'valid_days' => 'integer', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<WelfareStore, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(WelfareStore::class, 'welfare_store_id');
    }
}
