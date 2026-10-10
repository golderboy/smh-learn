<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSession;
use App\Models\LearningProgress;
use App\Models\LessonContent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearningService
{
    public function __construct(private readonly AuditService $audit) {}

    public function enroll(User $user, CourseSession $session, ?int $assignmentId = null, ?string $dueDate = null): CourseEnrollment
    {
        // The caller locks the session. A unique key also prevents retry duplicates.
        return CourseEnrollment::firstOrCreate(['course_session_id' => $session->id, 'user_id' => $user->id], [
            'assignment_id' => $assignmentId, 'department_id' => $user->department_id, 'position_id' => $user->position_id,
            'department_name' => $user->department?->name, 'position_name' => $user->position?->name,
            'state' => $assignmentId === null ? 'NOT_STARTED' : 'ASSIGNED', 'due_date' => $dueDate, 'enrolled_at' => now(),
        ]);
    }

    public function assertAccess(User $user, CourseEnrollment $enrollment, ?LessonContent $content = null, bool $sequential = true): void
    {
        abort_unless($user->is_active && $enrollment->user_id === $user->id, 403);
        abort_if($enrollment->cancelled_at !== null || ! $enrollment->session->isOpen(), 403, 'รอบเรียนนี้ปิดหรือการลงทะเบียนถูกยกเลิก');
        if ($enrollment->session->pretest_required && $enrollment->pretest_completed_at === null) {
            abort(409, 'ต้องทำ Pretest ให้เสร็จก่อนเริ่มเรียน');
        }
        if ($content !== null) {
            abort_unless($content->lesson->module->course_version_id === $enrollment->session->course_version_id && $content->lesson->status === 'active', 404);
            if ($sequential) {
                app(LearningOutline::class)->assertContentUnlocked($enrollment, $content);
            }
        }
    }

    public function openDocument(Request $request, CourseEnrollment $enrollment, LessonContent $content): LearningProgress
    {
        return DB::transaction(function () use ($request, $enrollment, $content): LearningProgress {
            $locked = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $this->assertAccess($this->actor($request), $locked, $content);
            $progress = LearningProgress::firstOrCreate(['enrollment_id' => $locked->id, 'content_id' => $content->id]);
            if ($progress->opened_at === null) {
                $progress->update(['opened_at' => now()]);
            }
            $this->start($locked);

            return $progress;
        });
    }

    public function confirm(Request $request, CourseEnrollment $enrollment, LessonContent $content): CourseEnrollment
    {
        return DB::transaction(function () use ($request, $enrollment, $content): CourseEnrollment {
            $locked = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $this->assertAccess($this->actor($request), $locked, $content);
            abort_if($content->content_type === 'video', 422, 'วิดีโอต้องผ่านเกณฑ์การรับชม');
            $progress = LearningProgress::query()->where('enrollment_id', $locked->id)->where('content_id', $content->id)->first();
            if ($progress === null || $progress->opened_at === null) {
                throw ValidationException::withMessages(['content' => 'เปิดเอกสารหรือเนื้อหาก่อนยืนยันว่าอ่านแล้ว']);
            }
            if ($progress->completed_at === null) {
                $progress->update(['completed_at' => now()]);
                $this->audit->record($request, 'learning.content_confirmed', 'lesson_content', $content->id, new: ['enrollment_id' => $locked->id]);
            }
            $this->recalculate($request, $locked);

            return $locked->refresh();
        });
    }

    /** @return array{key: string, progress: LearningProgress, enrollment: CourseEnrollment} */
    public function heartbeat(Request $request, CourseEnrollment $enrollment, LessonContent $content, float $position, ?string $key, bool $begin, ?float $actualDuration = null, bool $ended = false): array
    {
        return DB::transaction(function () use ($request, $enrollment, $content, $position, $key, $begin, $actualDuration, $ended): array {
            $locked = CourseEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $this->assertAccess($this->actor($request), $locked, $content);
            abort_unless($content->content_type === 'video' && $content->duration_seconds > 0, 422);
            if (in_array($content->video_source, ['youtube', 'remote'], true)) {
                abort_unless($actualDuration !== null && is_finite($actualDuration) && (int) ceil($actualDuration) === $content->duration_seconds, 409, 'ความยาววิดีโอไม่ตรงกับที่กำหนด กรุณาแจ้ง Instructor');
            }
            // The stored duration is rounded up to whole seconds. Use the matching
            // media duration for the denominator so watching to the end can reach 100%.
            $duration = $actualDuration !== null && is_finite($actualDuration) && $actualDuration > 0 && (int) ceil($actualDuration) === $content->duration_seconds ? $actualDuration : (float) $content->duration_seconds;
            abort_if($position < 0 || $position > $content->duration_seconds + 1, 422);
            $position = min($position, $duration);
            $progress = LearningProgress::firstOrCreate(['enrollment_id' => $locked->id, 'content_id' => $content->id]);
            $this->start($locked);
            if ($begin) {
                abort_if($position > (float) $progress->last_position_seconds + 1.25, 422, 'ไม่อนุญาตให้เลื่อนข้ามวิดีโอ กรุณากลับมาที่ตำแหน่งที่บันทึกไว้');
                $progress->playback_key = (string) Str::uuid();
                $progress->last_position_seconds = $position;
                $progress->last_heartbeat_at = now();
                $progress->opened_at ??= now();
            } else {
                abort_unless($key !== null && hash_equals((string) $progress->playback_key, $key), 409, 'มีการเปิดวิดีโอนี้จากอีกหน้าต่าง กรุณาเริ่มเล่นใหม่');
                $elapsed = $progress->last_heartbeat_at?->diffInMilliseconds(now()) / 1000;
                $previous = (float) $progress->last_position_seconds;
                $delta = $position - $previous;
                abort_if($delta > max(0, (float) $elapsed) + 1.25, 422, 'ไม่อนุญาตให้เลื่อนข้ามวิดีโอ');
                $ranges = $progress->watched_ranges ?? [];
                // Only contiguous playback at normal speed earns credit. Replays count once.
                if ($elapsed > 0 && $elapsed <= 30 && $delta > 0 && $delta <= $elapsed + 1.25) {
                    $ranges[] = [$previous, $previous + min($delta, $elapsed)];
                    usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
                    $merged = [];
                    foreach ($ranges as $range) {
                        $last = count($merged) - 1;
                        if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                            $merged[$last][1] = max($merged[$last][1], $range[1]);
                        } else {
                            $merged[] = $range;
                        }
                    }
                    $watched = array_sum(array_map(fn (array $range): float => (float) ($range[1] - $range[0]), $merged));
                    $progress->watched_ranges = $merged;
                    $progress->watched_seconds = min($watched, $duration);
                    $progress->watch_percent = max((float) $progress->watch_percent, min(100, $watched / $duration * 100));
                }
                // At the actual end, account for bounded startup/heartbeat clock jitter.
                // Recorded watched_seconds stays capped by server time; a forged end or
                // a meaningful skipped segment cannot satisfy both bounds.
                if ($ended && $position >= $duration - 0.05 && (float) $progress->watched_seconds >= $duration - min(1.25, $duration * 0.02)) {
                    $progress->watch_percent = 100;
                }
                if ($progress->completed_at === null && $progress->watch_percent >= (float) $locked->session->required_video_watch_percent) {
                    $progress->completed_at = now();
                    $this->audit->record($request, 'learning.video_completed', 'lesson_content', $content->id, new: ['enrollment_id' => $locked->id]);
                }
                $progress->last_position_seconds = $position;
                $progress->last_heartbeat_at = now();
            }
            $progress->save();
            $this->recalculate($request, $locked);

            return ['key' => (string) $progress->playback_key, 'progress' => $progress->fresh(), 'enrollment' => $locked->fresh()];
        });
    }

    private function start(CourseEnrollment $enrollment): void
    {
        if ($enrollment->started_at === null) {
            $enrollment->update(['started_at' => now(), 'state' => 'LEARNING']);
        }
    }

    public function recalculate(Request $request, CourseEnrollment $enrollment): void
    {
        DB::transaction(function () use ($request, $enrollment): void {
            $locked = CourseEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $this->calculate($request, $locked);
            $enrollment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function calculate(Request $request, CourseEnrollment $enrollment): void
    {
        $outline = app(LearningOutline::class);
        $lessons = $outline->modules($enrollment->session->version)->flatMap->lessons->where('is_required', true);
        $completedIds = LearningProgress::query()->where('enrollment_id', $enrollment->id)->whereNotNull('completed_at')->pluck('content_id');
        $complete = $lessons->filter(function ($lesson) use ($completedIds, $outline): bool {
            $required = $outline->requirements($lesson);

            return $required->isNotEmpty() && $required->every(fn ($content): bool => $completedIds->contains($content->id));
        })->count();
        $percent = $lessons->count() > 0 ? $complete / $lessons->count() * 100 : 0;
        $enrollment->progress_percent = round($percent, 2);
        if ($lessons->isNotEmpty() && $percent >= (float) $enrollment->session->required_progress_percent) {
            $enrollment->content_completed_at ??= now();
            if ($enrollment->session->posttest_required && $enrollment->posttest_completed_at === null) {
                $enrollment->state = 'CONTENT_COMPLETED';
            } else {
                if ($enrollment->completed_at === null) {
                    $this->audit->record($request, 'learning.course_completed', 'course_enrollment', $enrollment->id);
                }
                $enrollment->state = 'COMPLETED';
                $enrollment->completed_at ??= now();
            }
        }
        $enrollment->save();
        app(PointService::class)->award($request, $enrollment);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
