<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseVersion;
use App\Models\LearningProgress;
use App\Models\Lesson;
use App\Models\LessonContent;
use Illuminate\Support\Collection;

class LearningOutline
{
    /** @return Collection<int, CourseModule> */
    public function modules(CourseVersion $version): Collection
    {
        $modules = $version->modules()->with(['lessons' => fn ($query) => $query->where('status', 'active'), 'lessons.contents', 'lessons.documents'])->get();
        $linked = $modules->flatMap->lessons->flatMap->documents->pluck('id');
        // Imported PDF-only source lessons stay in the database for existing progress/history.
        foreach ($modules as $module) {
            $module->setRelation('lessons', $module->lessons->filter(fn (Lesson $lesson): bool => $lesson->contents->isEmpty() || $lesson->contents->contains(fn (LessonContent $content): bool => $content->content_type !== 'document' || ! $linked->contains($content->id)))->values());
        }

        return $modules->filter(fn ($module): bool => $module->lessons->isNotEmpty())->values();
    }

    /** @return Collection<int, LessonContent> */
    public function contents(Lesson $lesson): Collection
    {
        return $lesson->contents->concat($lesson->documents)->unique('id')->values();
    }

    /** @return Collection<int, LessonContent> */
    public function requirements(Lesson $lesson): Collection
    {
        $contents = $this->contents($lesson);
        $required = $contents->where('is_required', true);

        return $required->isNotEmpty() ? $required : $contents;
    }

    /**
     * @param  Collection<int, CourseModule>|null  $modules
     * @return array<int, array{unlocked: bool, complete: bool}>
     */
    public function states(CourseEnrollment $enrollment, ?Collection $modules = null): array
    {
        $completed = LearningProgress::where('enrollment_id', $enrollment->id)->whereNotNull('completed_at')->pluck('content_id');
        $unlocked = true;
        $states = [];
        foreach (($modules ?? $this->modules($enrollment->session->version))->flatMap->lessons as $lesson) {
            $required = $this->requirements($lesson);
            $complete = $required->isNotEmpty() && $required->every(fn ($content): bool => $completed->contains($content->id));
            $states[$lesson->id] = ['unlocked' => $unlocked, 'complete' => $complete];
            $unlocked = $unlocked && $complete;
        }

        return $states;
    }

    public function assertContentUnlocked(CourseEnrollment $enrollment, LessonContent $content): void
    {
        $modules = $this->modules($enrollment->session->version);
        $states = $this->states($enrollment, $modules);
        foreach ($modules->flatMap->lessons as $lesson) {
            if ($states[$lesson->id]['unlocked'] && $this->contents($lesson)->contains('id', $content->id)) {
                return;
            }
        }
        abort(409, 'ต้องเรียนบทก่อนหน้าให้ถึงเกณฑ์ก่อนเปิดบทนี้');
    }
}
