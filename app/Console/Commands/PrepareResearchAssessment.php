<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseSession;
use App\Models\Exam;
use App\Models\LessonContent;
use App\Models\QuestionImport;
use App\Models\User;
use App\Services\QuestionImportService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrepareResearchAssessment extends Command
{
    protected $signature = 'dev:prepare-research-assessment';

    protected $description = 'Enable the approved research exam and document presentation in the exact local DEV course only';

    public function handle(QuestionImportService $importer): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('Refusing changes outside the exact local DEV database.');

            return self::FAILURE;
        }
        $course = Course::where('code', 'RESEARCH-METHODS')->where('title', 'วิชา ระเบียบวิธีวิจัย')->firstOrFail();
        $version = $course->versions()->where('version_number', 1)->firstOrFail();
        $path = base_path('demo-learn/objective-exam-import1-normalized.xlsx');
        $sha = hash_file('sha256', $path);
        if (QuestionImport::where('course_version_id', $version->id)->where('sha256', $sha)->whereNotNull('committed_at')->exists()) {
            $this->info('The approved workbook is already imported; existing rules and history preserved.');

            return self::SUCCESS;
        }
        $rows = $importer->parse($path);
        if (count($rows) !== 50 || collect($rows)->where('usage', 'PRETEST')->count() !== 20 || collect($rows)->where('usage', 'POSTTEST')->count() !== 30) {
            $this->error('The supplied workbook identity/count guard failed.');

            return self::FAILURE;
        }
        $admin = User::where('username', '0000000000001')->where('is_demo', true)->where('is_active', true)->firstOrFail();
        abort_unless($admin->hasRole('administrator'), 403);
        $request = Request::create('/dev/prepare-research-assessment', 'POST');
        $request->setUserResolver(fn () => $admin);
        DB::transaction(function () use ($course, $version, $path, $request, $importer): void {
            Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            $examIds = $version->exams()->pluck('id');
            if (DB::table('exam_attempts')->whereIn('exam_id', $examIds)->exists() || $version->exams()->where('status', 'published')->exists()) {
                throw new \RuntimeException('Existing exam history or publication found; preparation refused.');
            }
            // Keep the known software fixtures in the bank/history, but exclude them from academic exams.
            $synthetic = DB::table('questions')->where('course_id', $course->id)->whereIn('code', ['DEMO-PRE001', 'DEMO-POST001', 'DEMO-BOTH001'])->pluck('id');
            DB::table('exam_questions')->whereIn('exam_id', $examIds)->whereIn('question_id', $synthetic)->delete();
            DB::table('questions')->whereIn('id', $synthetic)->update(['is_active' => false, 'updated_at' => now()]);
            $preview = $importer->preview($request, $version, $path, basename($path));
            $importer->commit($request, $version, $preview->token);
            Exam::where('course_version_id', $version->id)->update(['status' => 'published', 'result_policy' => 'HIGHEST', 'show_answer_policy' => 'NEVER']);
            CourseSession::where('course_version_id', $version->id)->update(['pretest_required' => true, 'posttest_required' => true]);
            $contents = LessonContent::whereHas('lesson.module', fn ($q) => $q->where('course_version_id', $version->id))->get();
            foreach ($contents->where('content_type', 'video') as $video) {
                if (! preg_match('/^บท(\d+)/u', (string) $video->original_name, $match)) {
                    continue;
                }
                $number = (int) $match[1];
                foreach ($contents->where('content_type', 'document') as $document) {
                    $name = (string) $document->original_name;
                    $shared = str_starts_with($name, 'บทที่ 1 - บทที่ 7') && $number >= 1 && $number <= 7;
                    $exact = preg_match('/^บทที่\s*(\d+)(?!\d)/u', $name, $docMatch) && (int) $docMatch[1] === $number;
                    if ($shared || $exact) {
                        DB::table('lesson_document_links')->updateOrInsert(['lesson_id' => $video->lesson_id, 'content_id' => $document->id], ['sort_order' => $shared ? 2 : 1, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'dev.research_assessment_prepared', 'entity_type' => 'course', 'entity_id' => $course->id, 'reason' => 'Approved 20 Pretest / 30 Posttest; required submissions without pass gate; original media, questions and enrollment history retained', 'created_at' => now()]);
        });
        $this->info('Imported 20 Pretest / 30 Posttest and enabled required assessments. Existing attempt limit, lesson files and learning history retained.');

        return self::SUCCESS;
    }
}
