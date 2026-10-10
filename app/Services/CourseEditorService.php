<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CourseEditorService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param callable(Course): mixed $work */
    public function mutate(Request $request, Course $course, string $action, callable $work): mixed
    {
        return DB::transaction(function () use ($request, $course, $action, $work): mixed {
            $locked = Course::query()->lockForUpdate()->findOrFail($course->id);
            Gate::forUser($request->user())->authorize('manage', $locked);
            $result = $work($locked);
            $reason = $request->input('reason');
            $this->audit->record($request, $action, 'course', $locked->id, reason: is_string($reason) ? $reason : null);

            return $result;
        });
    }

    public function draft(CourseVersion $version): void
    {
        if ($version->published_at !== null) {
            throw ValidationException::withMessages(['course' => 'เวอร์ชันนี้เผยแพร่แล้ว กรุณาสร้างเวอร์ชันใหม่ก่อนแก้เนื้อหา']);
        }
    }

    public function cloneVersion(Course $course, CourseVersion $source, string $name): CourseVersion
    {
        $version = $course->versions()->create(['version_number' => (int) $course->versions()->max('version_number') + 1, 'version_name' => $name, 'description' => $source->description]);
        $lessonIds = [];
        $contentIds = [];
        foreach ($source->modules()->with('lessons.contents')->get() as $module) {
            $newModule = $version->modules()->create($module->only(['title', 'sort_order']));
            foreach ($module->lessons as $lesson) {
                $newLesson = $newModule->lessons()->create($lesson->only(['title', 'description', 'sort_order', 'is_required', 'status']));
                $lessonIds[$lesson->id] = $newLesson->id;
                foreach ($lesson->contents as $content) {
                    $newContent = $newLesson->contents()->create(Arr::except($content->getAttributes(), ['id', 'lesson_id', 'created_at', 'updated_at']));
                    $contentIds[$content->id] = $newContent->id;
                }
            }
        }
        foreach (DB::table('lesson_document_links')->whereIn('lesson_id', array_keys($lessonIds))->get() as $link) {
            if (isset($contentIds[$link->content_id])) {
                DB::table('lesson_document_links')->insert(['lesson_id' => $lessonIds[$link->lesson_id], 'content_id' => $contentIds[$link->content_id], 'sort_order' => $link->sort_order, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach ($source->exams as $exam) {
            $newExam = $version->exams()->create([...Arr::except($exam->getAttributes(), ['id', 'course_version_id', 'created_at', 'updated_at']), 'status' => 'draft']);
            foreach (DB::table('exam_questions')->where('exam_id', $exam->id)->get() as $question) {
                DB::table('exam_questions')->insert(['exam_id' => $newExam->id, 'question_id' => $question->question_id, 'sort_order' => $question->sort_order, 'question_snapshot' => $question->question_snapshot]);
            }
        }

        return $version;
    }

    public function publish(CourseVersion $version): void
    {
        $this->draft($version);
        $lessons = $version->modules()->with(['lessons.contents', 'lessons.documents.lesson.module'])->get()->flatMap->lessons->where('status', 'active');
        if ($lessons->isEmpty() || $lessons->where('is_required', true)->isEmpty() || $lessons->contains(fn ($lesson): bool => $lesson->contents->isEmpty() || ($lesson->is_required && $lesson->contents->where('is_required', true)->isEmpty()))) {
            throw ValidationException::withMessages(['course' => 'ต้องมีบทเรียนที่จำเป็นพร้อมเนื้อหาที่จำเป็นอย่างน้อยหนึ่งรายการ และทุกบทต้องมีเนื้อหา']);
        }
        if ($lessons->contains(fn ($lesson): bool => $lesson->documents->contains(fn ($document): bool => $document->lesson->status !== 'active' || $document->lesson->module->course_version_id !== $version->id))) {
            throw ValidationException::withMessages(['course' => 'เอกสารที่จับคู่ต้องมาจากบทที่เปิดใช้ในเวอร์ชันเดียวกัน กรุณาเปิดใช้แหล่งเอกสารหรือนำการจับคู่ออกก่อนเผยแพร่']);
        }
        $version->update(['published_at' => now()]);
        $version->course->update(['status' => 'published']);
    }
}
