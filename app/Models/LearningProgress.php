<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property list<array<int, float>>|null $watched_ranges
 * @property Carbon|null $last_heartbeat_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $completed_at
 */
class LearningProgress extends Model
{
    protected $table = 'learning_progress';

    protected $guarded = ['id'];

    protected $hidden = ['playback_key', 'watched_ranges'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['watched_ranges' => 'array', 'last_heartbeat_at' => 'datetime', 'opened_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
