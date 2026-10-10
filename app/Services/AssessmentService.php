<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\CourseSession;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentService
{
    public function __construct(private readonly AssessmentRules $rules, private readonly LearningService $learning, private readonly AuditService $audit) {}

    public function start(Request $request, CourseEnrollment $enrollment, string $type): ExamAttempt
    {
        return DB::transaction(function () use ($request, $enrollment, $type): ExamAttempt {
            CourseSession::whereKey($enrollment->course_session_id)->lockForUpdate()->firstOrFail();
            $locked = CourseEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $this->access($request, $locked);
            $exam = Exam::where('course_version_id', $locked->session->course_version_id)->where('exam_type', $type)->lockForUpdate()->first();
            if ($exam === null || $exam->status !== 'published') {
                $this->invalid('ชุดสอบยังไม่พร้อม กรุณาติดต่อ Instructor');
            }
            if ($type === 'POSTTEST' && ($locked->pretest_completed_at === null || $locked->content_completed_at === null)) {
                $this->invalid('ต้องทำ Pretest และเรียนครบก่อนสอบ Posttest');
            }
            $active = ExamAttempt::where('enrollment_id', $locked->id)->where('exam_id', $exam->id)->where('status', 'IN_PROGRESS')->lockForUpdate()->first();
            if ($active !== null) {
                if ($active->expires_at?->isPast()) {
                    $this->grade($request, $locked, $active, true);
                }

                return $active->refresh(); // Resume/expire the existing attempt; never consume another on retry.
            }
            if ($type === 'PRETEST' && $locked->pretest_completed_at !== null) {
                $this->invalid('ทำ Pretest เรียบร้อยแล้ว');
            }
            if ($type === 'POSTTEST') {
                $this->rules->assertPosttestAllowed($locked, $exam);
            }
            $questions = DB::table('exam_questions')->where('exam_id', $exam->id)->orderBy('sort_order')->get();
            if ($exam->randomize_questions) {
                $questions = $questions->shuffle();
            }
            $questions = $questions->take($exam->question_count ?? $questions->count());
            if ($questions->isEmpty()) {
                $this->invalid('ชุดสอบไม่มีคำถาม');
            }
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->id, 'enrollment_id' => $locked->id,
                'attempt_number' => ExamAttempt::where('exam_id', $exam->id)->where('enrollment_id', $locked->id)->count() + 1,
                'status' => 'IN_PROGRESS', 'started_at' => now(),
                'expires_at' => $exam->time_limit_minutes ? now()->addMinutes($exam->time_limit_minutes) : null,
                'rules_snapshot' => ['exam_type' => $type, 'passing_score' => (float) $locked->session->passing_score, 'max_attempts' => $locked->session->max_attempts, 'result_policy' => 'HIGHEST', 'show_answer_policy' => 'NEVER', 'randomize_questions' => (bool) $exam->randomize_questions, 'randomize_choices' => (bool) $exam->randomize_choices, 'time_limit_minutes' => $exam->time_limit_minutes],
            ]);
            $order = 1;
            foreach ($questions as $question) {
                $snapshot = json_decode($question->question_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $snapshot['display_order'] = $order++;
                $snapshot['choice_order'] = $exam->randomize_choices ? collect(array_keys($snapshot['choices']))->shuffle()->all() : array_keys($snapshot['choices']);
                DB::table('exam_answers')->insert(['attempt_id' => $attempt->id, 'question_id' => $question->question_id, 'question_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record($request, 'assessment.started', 'exam_attempt', $attempt->id, new: ['exam_type' => $type, 'attempt_number' => $attempt->attempt_number]);

            return $attempt;
        });
    }

    /** @param list<array{question_id:int, selected_answer:string|null}> $answers */
    public function update(Request $request, ExamAttempt $attempt, array $answers = [], ?int $revision = null, bool $submit = false): ExamAttempt
    {
        return DB::transaction(function () use ($request, $attempt, $answers, $revision, $submit): ExamAttempt {
            $enrollment = CourseEnrollment::findOrFail($attempt->enrollment_id);
            CourseSession::whereKey($enrollment->course_session_id)->lockForUpdate()->firstOrFail();
            $enrollment = CourseEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $this->access($request, $enrollment, requireOpen: false);
            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'IN_PROGRESS') {
                return $locked; // Final results are immutable, including duplicate submissions.
            }
            $this->access($request, $enrollment);
            if ($locked->expires_at?->lessThanOrEqualTo(now())) {
                $this->grade($request, $enrollment, $locked, true);

                return $locked->refresh(); // Late answers cannot enter the score.
            }
            if ($revision !== null && $revision !== $locked->revision) {
                abort(409, 'คำตอบมีการเปลี่ยนจากอีกหน้าต่าง กรุณาโหลดข้อสอบใหม่');
            }
            $owned = DB::table('exam_answers')->where('attempt_id', $locked->id)->get()->keyBy('question_id');
            $seen = [];
            foreach ($answers as $answer) {
                $question = $owned->get($answer['question_id']);
                $choices = $question === null ? [] : json_decode($question->question_snapshot, true, 512, JSON_THROW_ON_ERROR)['choices'];
                if ($question === null || isset($seen[$answer['question_id']]) || ($answer['selected_answer'] !== null && (! in_array($answer['selected_answer'], AssessmentRules::CHOICE_KEYS, true) || ! array_key_exists($answer['selected_answer'], $choices)))) {
                    $this->invalid('คำตอบไม่ตรงกับข้อสอบนี้');
                }
                $seen[$answer['question_id']] = true;
                DB::table('exam_answers')->where('attempt_id', $locked->id)->where('question_id', $answer['question_id'])->update(['selected_answer' => $answer['selected_answer'], 'answered_at' => now(), 'updated_at' => now()]);
            }
            if ($answers !== []) {
                $locked->increment('revision');
            }
            if ($submit) {
                if (DB::table('exam_answers')->where('attempt_id', $locked->id)->whereNull('selected_answer')->exists()) {
                    $this->invalid('กรุณาตอบให้ครบทุกข้อก่อนส่งข้อสอบ');
                }
                $this->grade($request, $enrollment, $locked, false);
            }

            return $locked->refresh();
        });
    }

    private function grade(Request $request, CourseEnrollment $enrollment, ExamAttempt $attempt, bool $expired): void
    {
        $score = 0.0;
        $max = 0.0;
        foreach (DB::table('exam_answers')->where('attempt_id', $attempt->id)->get() as $answer) {
            $snapshot = json_decode($answer->question_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $awarded = $this->rules->singleChoiceScore((string) $answer->selected_answer, $snapshot['correct_answer'], (float) $snapshot['max_score']);
            $score += $awarded;
            $max += (float) $snapshot['max_score'];
            DB::table('exam_answers')->where('id', $answer->id)->update(['score_awarded' => $awarded, 'is_correct' => $answer->selected_answer === $snapshot['correct_answer'], 'updated_at' => now()]);
        }
        $passed = $max > 0 && $score * 100 >= $max * $attempt->rules_snapshot['passing_score'];
        $attempt->update(['status' => $expired ? 'EXPIRED' : 'GRADED', 'submitted_at' => now(), 'graded_at' => now(), 'score' => round($score, 2), 'max_score' => round($max, 2), 'percentage' => $max > 0 ? round($score / $max * 100, 2) : 0, 'passed' => $passed]);
        if ($attempt->rules_snapshot['exam_type'] === 'PRETEST') {
            $enrollment->pretest_completed_at ??= now();
            if ($enrollment->state === 'NOT_STARTED' || $enrollment->state === 'ASSIGNED') {
                $enrollment->state = 'PRETEST_COMPLETED';
            }
        } else {
            $enrollment->posttest_completed_at ??= now();
            if ($passed) {
                $enrollment->posttest_passed_at ??= now();
            }
        }
        $enrollment->save();
        $this->learning->recalculate($request, $enrollment);
        $this->audit->record($request, 'assessment.graded', 'exam_attempt', $attempt->id, new: ['score' => $score, 'percentage' => $attempt->percentage, 'expired' => $expired]);
    }

    /**
     * Learner projection intentionally never serializes answer keys or explanations.
     *
     * @return array<string, mixed>
     */
    public function present(ExamAttempt $attempt): array
    {
        $questions = DB::table('exam_answers')->where('attempt_id', $attempt->id)->get()->map(function ($answer): array {
            $snapshot = json_decode($answer->question_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return ['question_id' => $answer->question_id, 'order' => $snapshot['display_order'], 'question_text' => $snapshot['question_text'], 'max_score' => $snapshot['max_score'], 'choices' => array_map(fn ($key): array => ['key' => $key, 'text' => $snapshot['choices'][$key]], $snapshot['choice_order']), 'selected_answer' => $answer->selected_answer];
        })->sortBy('order')->values();

        return ['attempt' => $attempt, 'examType' => $attempt->rules_snapshot['exam_type'], 'questions' => $questions, 'serverNow' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function summary(CourseEnrollment $enrollment): array
    {
        $exams = Exam::where('course_version_id', $enrollment->session->course_version_id)->get();
        $attempts = ExamAttempt::where('enrollment_id', $enrollment->id)->orderBy('id')->get();
        $postIds = $exams->where('exam_type', 'POSTTEST')->pluck('id');
        $posts = $attempts->whereIn('exam_id', $postIds);
        $graded = $posts->whereNotNull('graded_at');
        $best = $graded->sortByDesc('percentage')->first();

        return ['pretestReady' => $exams->where('exam_type', 'PRETEST')->where('status', 'published')->isNotEmpty(), 'posttestReady' => $exams->where('exam_type', 'POSTTEST')->where('status', 'published')->isNotEmpty(), 'usedPosttestAttempts' => $posts->count(), 'maxPosttestAttempts' => $enrollment->session->max_attempts, 'bestPosttest' => $best, 'attempts' => $attempts->map(fn ($attempt): array => [...$attempt->toArray(), 'exam_type' => $attempt->rules_snapshot['exam_type']])];
    }

    private function access(Request $request, CourseEnrollment $enrollment, bool $requireOpen = true): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active && $enrollment->user_id === $actor->id, 403);
        if ($requireOpen) {
            abort_unless($enrollment->cancelled_at === null && $enrollment->session->isOpen(), 403, 'รอบเรียนนี้ปิดหรือการเรียนถูกยกเลิก');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['exam' => $message]);
    }
}
