<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $completed_at
 * @property Carbon|null $due_date
 * @property bool $points_ineligible
 */
class CourseEnrollment extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime', 'started_at' => 'datetime', 'content_completed_at' => 'datetime', 'pretest_completed_at' => 'datetime', 'posttest_completed_at' => 'datetime', 'posttest_passed_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'due_date' => 'date:Y-m-d', 'points_ineligible' => 'boolean'];
    }

    /** @return BelongsTo<CourseSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'course_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
