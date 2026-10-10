<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourseSession;
use App\Models\CourseVersion;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Services\CourseEditorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssessmentSettingsController extends Controller
{
    public function session(Request $request, CourseSession $session, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['max_attempts' => ['required', 'integer', 'min:1', 'max:100']]);
        $editor->mutate($request, $session->version->course, 'assessment.attempt_limit_changed', function () use ($session, $data): void {
            $locked = CourseSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $counts = DB::table('exam_attempts')->join('exams', 'exam_attempts.exam_id', '=', 'exams.id')->join('course_enrollments', 'exam_attempts.enrollment_id', '=', 'course_enrollments.id')->where('course_enrollments.course_session_id', $locked->id)->where('exams.exam_type', 'POSTTEST')->groupBy('enrollment_id')->selectRaw('COUNT(*) as used');
            $used = DB::query()->fromSub($counts, 'attempt_counts')->max('used') ?? 0;
            if ($data['max_attempts'] < $used) {
                throw ValidationException::withMessages(['max_attempts' => 'ลดจำนวนครั้งต่ำกว่าประวัติที่ใช้ไปแล้วไม่ได้']);
            }
            $locked->update(['max_attempts' => $data['max_attempts']]);
        });

        return back()->with('message', 'บันทึกจำนวนครั้ง Posttest รวมทั้งหมดแล้ว');
    }

    public function exam(Request $request, Exam $exam, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'published'])], 'question_count' => ['nullable', 'integer', 'min:1', 'max:1000'], 'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'], 'randomize_questions' => ['required', 'boolean'], 'randomize_choices' => ['required', 'boolean']]);
        $version = CourseVersion::findOrFail($exam->course_version_id);
        $editor->mutate($request, $version->course, 'assessment.exam_configured', function () use ($exam, $data): void {
            $locked = Exam::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            if (DB::table('exam_attempts')->where('exam_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['exam' => 'ชุดสอบมีประวัติแล้ว กรุณาสร้างเวอร์ชันใหม่เพื่อเปลี่ยนกติกาหรือข้อสอบ']);
            }
            $count = DB::table('exam_questions')->where('exam_id', $locked->id)->count();
            if ($count === 0 || ($data['question_count'] ?? $count) > $count) {
                throw ValidationException::withMessages(['question_count' => 'จำนวนข้อที่ใช้สอบต้องไม่เกินจำนวนข้อในชุด']);
            }
            $locked->update([...$data, 'result_policy' => 'HIGHEST', 'show_answer_policy' => 'NEVER']);
        });

        return back()->with('message', 'บันทึกและเปลี่ยนสถานะชุดสอบแล้ว');
    }

    public function documents(Request $request, Lesson $lesson, CourseEditorService $editor): RedirectResponse
    {
        $data = $request->validate(['content_ids' => ['present', 'array', 'max:100'], 'content_ids.*' => ['integer', 'distinct']]);
        $editor->mutate($request, $lesson->module->version->course, 'course.document_display_updated', function () use ($editor, $lesson, $data): void {
            $editor->draft($lesson->module->version->fresh());
            $ids = $data['content_ids'];
            $count = LessonContent::whereIn('id', $ids)->where('content_type', 'document')->whereHas('lesson.module', fn ($query) => $query->where('course_version_id', $lesson->module->course_version_id))->count();
            if ($count !== count($ids)) {
                throw ValidationException::withMessages(['documents' => 'เลือกได้เฉพาะ PDF ในเวอร์ชันเดียวกัน']);
            }
            DB::table('lesson_document_links')->where('lesson_id', $lesson->id)->delete();
            foreach ($ids as $order => $id) {
                DB::table('lesson_document_links')->insert(['lesson_id' => $lesson->id, 'content_id' => $id, 'sort_order' => $order + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('message', 'จับคู่เอกสารสำหรับแสดงใต้บทเรียนแล้ว');
    }
}
