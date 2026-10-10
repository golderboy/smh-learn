<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\Exam;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentRules
{
    public const CHOICE_KEYS = ['A', 'B', 'C', 'D', 'E', 'F'];

    public const CHOICE_LABELS = ['A' => 'ก', 'B' => 'ข', 'C' => 'ค', 'D' => 'ง', 'E' => 'จ', 'F' => 'ฉ'];

    /** Phase 4 must call this while holding a FOR UPDATE lock on the enrollment. */
    public function assertPosttestAllowed(CourseEnrollment $enrollment, Exam $exam): void
    {
        if ($exam->exam_type !== 'POSTTEST' || $exam->course_version_id !== $enrollment->session->course_version_id || $enrollment->content_completed_at === null || $enrollment->cancelled_at !== null) {
            throw ValidationException::withMessages(['exam' => 'ต้องผ่านเงื่อนไขการเรียนในรอบนี้ก่อนสอบ Posttest']);
        }
        $used = DB::table('exam_attempts')->where('enrollment_id', $enrollment->id)->where('exam_id', $exam->id)->count();
        if ($used >= $enrollment->session->max_attempts) {
            throw ValidationException::withMessages(['exam' => 'ใช้สิทธิ์สอบ Posttest ครบจำนวนครั้งแล้ว']);
        }
    }

    /** Phase 4 compares the selected key to the frozen answer key on the server. */
    public function singleChoiceScore(string $selected, string $correct, float $maxScore): float
    {
        return in_array($selected, self::CHOICE_KEYS, true) && $selected === $correct ? $maxScore : 0.0;
    }
}
