<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetLearningQa extends Command
{
    protected $signature = 'dev:reset-learning-qa {code : Exact temporary browser fixture code}';

    protected $description = 'Remove only a designated synthetic browser fixture in the local DEV database';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev' || ! preg_match('/^SMH-E2E-(desktop|tablet|mobile)-chromium$/', $code)) {
            $this->error('Refusing cleanup outside the exact local synthetic fixture.');

            return self::FAILURE;
        }
        $course = Course::where('code', $code)->first();
        if ($course === null) {
            return self::SUCCESS;
        }
        $creator = User::whereKey($course->created_by)->where('is_demo', true)->where('username', '0000000000001')->first();
        if ($creator === null || $course->title !== 'การทดสอบอัตโนมัติ '.$code) {
            $this->error('Fixture identity guard failed.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($course, $code): void {
            $versionIds = $course->versions()->pluck('id');
            $sessionIds = DB::table('course_sessions')->whereIn('course_version_id', $versionIds)->pluck('id');
            $enrollments = DB::table('course_enrollments')->whereIn('course_session_id', $sessionIds)->get();
            if (User::whereIn('id', $enrollments->pluck('user_id'))->where('is_demo', false)->exists()) {
                throw new \RuntimeException('Non-demo enrollment found; cleanup refused.');
            }
            $examIds = DB::table('exams')->whereIn('course_version_id', $versionIds)->pluck('id');
            $attempts = DB::table('exam_attempts')->whereIn('exam_id', $examIds)->get();
            if ($attempts->contains(fn ($attempt) => ! $enrollments->pluck('id')->contains($attempt->enrollment_id))) {
                throw new \RuntimeException('Exam history outside this synthetic fixture found; cleanup refused.');
            }
            $moduleIds = DB::table('course_modules')->whereIn('course_version_id', $versionIds)->pluck('id');
            $lessonIds = DB::table('lessons')->whereIn('module_id', $moduleIds)->pluck('id');
            $questionIds = DB::table('questions')->where('course_id', $course->id)->pluck('id');
            DB::table('exam_answers')->whereIn('attempt_id', $attempts->pluck('id'))->delete();
            DB::table('exam_attempts')->whereIn('id', $attempts->pluck('id'))->delete();
            DB::table('lesson_document_links')->whereIn('lesson_id', $lessonIds)->delete();
            DB::table('learning_progress')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            // Only the exact guarded synthetic fixture may bypass immutable ledger model events.
            DB::table('point_transactions')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            DB::table('point_transactions')->whereIn('user_id', $enrollments->pluck('user_id'))->where('created_by', $course->created_by)->where('transaction_type', 'ADMIN_ADJUSTMENT')->whereIn('description', ['QA '.$code.' ปรับแต้มผ่านหน้าจอ', 'QA '.$code.' ชดเชยแต้มผ่านหน้าจอ'])->delete();
            foreach (ReportExport::where('filters->course_id', $course->id)->get() as $export) {
                if ($export->storage_path !== null) {
                    Storage::disk('reports')->delete($export->storage_path);
                }
                $export->delete();
            }
            DB::table('course_enrollments')->whereIn('id', $enrollments->pluck('id'))->delete();
            DB::table('course_assignments')->whereIn('course_session_id', $sessionIds)->delete();
            DB::table('course_sessions')->whereIn('id', $sessionIds)->delete();
            DB::table('question_imports')->whereIn('course_version_id', $versionIds)->delete();
            DB::table('exam_questions')->whereIn('exam_id', $examIds)->delete();
            DB::table('exams')->whereIn('id', $examIds)->delete();
            DB::table('question_choices')->whereIn('question_id', $questionIds)->delete();
            DB::table('questions')->whereIn('id', $questionIds)->delete();
            DB::table('question_categories')->where('course_id', $course->id)->delete();
            DB::table('lesson_contents')->whereIn('lesson_id', $lessonIds)->delete();
            DB::table('lessons')->whereIn('id', $lessonIds)->delete();
            DB::table('course_modules')->whereIn('id', $moduleIds)->delete();
            DB::table('course_versions')->whereIn('id', $versionIds)->delete();
            $course->delete();
            DB::table('audit_logs')->insert(['user_id' => $course->created_by, 'action' => 'dev.learning_qa_cleaned', 'entity_type' => 'course', 'entity_id' => $course->id, 'reason' => 'Removed the designated synthetic browser fixture only; private shared media retained', 'created_at' => now()]);
        });
        $this->info('Removed '.$code.' only. Research course, demo people and audit history retained.');

        return self::SUCCESS;
    }
}
