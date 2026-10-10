<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\LessonContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LearningDevStatus extends Command
{
    protected $signature = 'dev:learning-status';

    protected $description = 'Read the authorized research demo course status, without credentials or personal identifiers';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            return self::FAILURE;
        }
        $course = Course::where('code', 'RESEARCH-METHODS')->firstOrFail();
        $version = $course->versions()->where('version_number', 1)->firstOrFail();
        $session = $version->sessions()->firstOrFail();
        $lessons = $version->modules()->with('lessons')->get()->flatMap->lessons;
        $contents = LessonContent::whereIn('lesson_id', $lessons->pluck('id'))->get();
        $this->line(json_encode([
            'course_id' => $course->id, 'title' => $course->title, 'category_id' => $course->category_id, 'category' => $course->category->name,
            'version_id' => $version->id, 'session_id' => $session->id, 'lessons' => $lessons->count(),
            'videos' => $contents->where('content_type', 'video')->count(), 'documents' => $contents->where('content_type', 'document')->count(),
            'bytes' => $contents->sum('file_size'), 'video_seconds' => $contents->sum('duration_seconds'),
            'max_posttest_total_attempts' => $session->max_attempts, 'pretest_required' => $session->pretest_required,
            'posttest_required' => $session->posttest_required, 'session_status' => $session->status,
            'question_count' => DB::table('questions')->where('course_id', $course->id)->count(),
            'active_questions' => DB::table('questions')->where('course_id', $course->id)->where('is_active', true)->count(),
            'document_links' => DB::table('lesson_document_links')->whereIn('lesson_id', $lessons->pluck('id'))->count(),
            'exams' => $version->exams()->get()->map(fn ($exam) => ['id' => $exam->id, 'exam_type' => $exam->exam_type, 'status' => $exam->status, 'grading_mode' => $exam->grading_mode, 'questions' => DB::table('exam_questions')->where('exam_id', $exam->id)->count()]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
