<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use App\Services\CourseEditorService;
use App\Services\LearningMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportResearchCourse extends Command
{
    protected $signature = 'dev:import-research-course {--publish : Publish the imported version and open the optional DEV session}';

    protected $description = 'Import the authorized local research course and its MP4/PDF files into DEV only';

    public function handle(LearningMediaService $media, CourseEditorService $editor): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('Refusing import outside the local smh_learning_dev database.');

            return self::FAILURE;
        }
        $actor = User::where('username', '0000000000001')->where('is_demo', true)->firstOrFail();
        if (! $actor->hasRole('administrator')) {
            $this->error('The designated demo administrator is required.');

            return self::FAILURE;
        }
        $root = realpath(base_path('demo-learn/วิชา ระเบียบวิธีวิจัย'));
        if ($root === false) {
            $this->error('Authorized source folder was not found.');

            return self::FAILURE;
        }
        $files = glob($root.'/*') ?: [];
        natsort($files);
        $metadata = [];
        foreach ($files as $path) {
            if (! is_file($path) || is_link($path) || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'pdf'], true)) {
                continue;
            }
            $type = str_ends_with(strtolower($path), '.mp4') ? 'video' : 'document';
            $metadata[] = ['type' => $type, 'name' => basename($path), 'file' => $media->store($path, basename($path), $type)];
            $this->line(basename($path).' | '.$type.' | '.($metadata[array_key_last($metadata)]['file']['duration_seconds'] ?? 0).'s');
        }
        if (count($metadata) !== 24) {
            $this->error('Expected the 14 MP4 and 10 PDF source files. No course records were created.');

            return self::FAILURE;
        }
        $course = DB::transaction(function () use ($actor, $metadata, $editor): Course {
            $category = CourseCategory::firstOrCreate(['name' => 'พัฒนาองค์ความรู้องค์กร'], ['code' => 'ORG-KNOWLEDGE', 'description' => 'การพัฒนาองค์ความรู้ขององค์กร', 'is_active' => true, 'created_by' => $actor->id]);
            $course = Course::firstOrCreate(['code' => 'RESEARCH-METHODS'], ['category_id' => $category->id, 'title' => 'วิชา ระเบียบวิธีวิจัย', 'description' => 'หลักสูตรจากเอกสารและวิดีโอที่หน่วยงานจัดเตรียม', 'created_by' => $actor->id]);
            if (! $course->wasRecentlyCreated) {
                $this->warn('Course already exists; existing content and learning history were preserved.');

                return $course;
            }
            $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ฉบับที่ 1']);
            foreach (['video' => 'บทเรียนวิดีโอ', 'document' => 'เอกสารประกอบการเรียน'] as $type => $title) {
                $module = $version->modules()->create(['title' => $title, 'sort_order' => $type === 'video' ? 1 : 2]);
                $order = 1;
                foreach ($metadata as $item) {
                    if ($item['type'] !== $type) {
                        continue;
                    }
                    $lesson = $module->lessons()->create(['title' => pathinfo($item['name'], PATHINFO_FILENAME), 'sort_order' => $order++, 'is_required' => true]);
                    $lesson->contents()->create([...$item['file'], 'title' => $item['name'], 'content_type' => $type, 'is_required' => true]);
                }
            }
            $year = now()->year + 543 + (now()->month >= 10 ? 1 : 0);
            $version->sessions()->create(['name' => 'รอบเรียนที่ 1', 'fiscal_year' => $year, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'enrollment_mode' => 'optional', 'max_attempts' => 1, 'required_video_watch_percent' => 80, 'required_progress_percent' => 100, 'pretest_required' => false, 'posttest_required' => false]);
            if ($this->option('publish')) {
                $editor->publish($version);
                $version->sessions()->update(['status' => 'open']);
            }
            DB::table('audit_logs')->insert(['user_id' => $actor->id, 'action' => 'dev.research_course_imported', 'entity_type' => 'course', 'entity_id' => $course->id, 'new_values' => json_encode(['videos' => 14, 'documents' => 10, 'posttest_max_total_attempts' => 1, 'source' => 'authorized-local-demo-folder'], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $course;
        });
        $this->info('Imported course ID '.$course->id.' / '.$course->title.' (private media, optional enrollment, posttest total attempts = 1).');

        return self::SUCCESS;
    }
}
