<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon $start_date
 * @property Carbon $end_date
 * @property Carbon|null $points_activated_at
 */
class CourseSession extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'pretest_required' => 'boolean', 'posttest_required' => 'boolean', 'is_mandatory' => 'boolean', 'max_attempts' => 'integer', 'points_enabled' => 'boolean', 'point_rules_snapshot' => 'array', 'points_activated_at' => 'datetime'];
    }

    /** @return BelongsTo<CourseVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class, 'course_version_id');
    }

    /** @return HasMany<CourseEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && $this->start_date->startOfDay()->lte(now()) && $this->end_date->endOfDay()->gte(now()) && $this->version->is_active && $this->version->course->status === 'published' && $this->version->course->category->is_active;
    }
}
