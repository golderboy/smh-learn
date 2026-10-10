<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\CourseSession;
use App\Models\Department;
use App\Models\Exam;
use App\Models\LearningProgress;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\User;
use App\Services\AssessmentRules;
use App\Services\LearningHtml;
use App\Services\LearningMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

class LearningEngineTest extends FoundationTestCase
{
    /** @return array{User, Course, CourseSession, Lesson} */
    private function fixture(): array
    {
        $admin = $this->userWithRole('administrator');
        $category = CourseCategory::create(['code' => 'TEST-LEARNING', 'name' => 'หมวดทดสอบ']);
        $course = Course::create(['category_id' => $category->id, 'code' => 'TEST-COURSE', 'title' => 'หลักสูตรทดสอบ', 'status' => 'published', 'created_by' => $admin->id]);
        $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ทดสอบ 1', 'published_at' => now()]);
        $module = $version->modules()->create(['title' => 'หมวดบททดสอบ']);
        $lesson = $module->lessons()->create(['title' => 'บททดสอบ', 'is_required' => true]);
        $session = $version->sessions()->create(['name' => 'รอบทดสอบ', 'fiscal_year' => 2570, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'status' => 'open', 'enrollment_mode' => 'optional']);

        return [$admin, $course, $session, $lesson];
    }

    private function enroll(User $user, CourseSession $session): CourseEnrollment
    {
        $this->actingAs($user)->post('/sessions/'.$session->id.'/enroll')->assertRedirect();

        return CourseEnrollment::where('user_id', $user->id)->where('course_session_id', $session->id)->firstOrFail();
    }

    private function video(Lesson $lesson): LessonContent
    {
        return $lesson->contents()->create(['title' => 'วิดีโอทดสอบ', 'content_type' => 'video', 'duration_seconds' => 100, 'is_required' => true, 'storage_path' => 'media/aa/'.str_repeat('a', 64).'.mp4', 'original_name' => 'video.mp4', 'mime_type' => 'video/mp4']);
    }

    /** @return array<string, mixed> */
    private function beat(CourseEnrollment $enrollment, LessonContent $content, float $position, ?string $key = null, bool $begin = false): array
    {
        return $this->postJson('/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat', ['position' => $position, 'key' => $key, 'begin' => $begin])->assertOk()->json();
    }

    public function test_optional_enrollment_is_unique_and_snapshots_department(): void
    {
        [, $course, $session] = $this->fixture();
        $department = Department::create(['code' => 'TEST-HISTORY', 'name' => 'แผนกก่อนย้าย']);
        $learner = $this->userWithRole('learner');
        $learner->update(['department_id' => $department->id]);
        $enrollment = $this->enroll($learner, $session);
        $this->post('/sessions/'.$session->id.'/enroll')->assertRedirect();
        $this->assertSame(1, CourseEnrollment::count());
        $this->assertSame('แผนกก่อนย้าย', $enrollment->department_name);
        $department->update(['name' => 'แผนกหลังย้าย']);
        $this->assertSame('แผนกก่อนย้าย', $enrollment->fresh()->department_name);
        $this->get('/courses/'.$course->id)->assertOk();
        $this->get('/learning/'.$enrollment->id)->assertOk();
        $this->assertSame(1, $session->fresh()->max_attempts);
    }

    public function test_instructor_scope_guards_course_creation_edit_media_and_assignment(): void
    {
        [, $course, $session, $lesson] = $this->fixture();
        $instructor = $this->userWithRole('instructor');
        $this->actingAs($instructor)->get('/manage/courses/'.$course->id.'/edit')->assertForbidden();
        $this->post('/manage/courses', ['category_id' => $course->category_id, 'code' => 'FORGED', 'title' => 'ข้ามหมวด'])->assertForbidden();
        $this->post('/sessions/'.$session->id.'/assign', ['assignment_type' => 'all'])->assertForbidden();
        $content = $this->video($lesson);
        $this->get('/media/'.$content->id.'?enrollment=1')->assertNotFound();
        $instructor->supervisedCategories()->attach($course->category_id);
        $this->get('/manage/courses/'.$course->id.'/edit')->assertOk();
        $this->get('/versions/'.$session->course_version_id.'/questions')->assertOk();
        $instructor->supervisedCategories()->detach($course->category_id);
        $this->get('/versions/'.$session->course_version_id.'/questions')->assertForbidden();
    }

    public function test_assignment_snapshots_targets_and_does_not_duplicate_or_overwrite_existing_enrollment(): void
    {
        [$admin, , $session] = $this->fixture();
        $department = Department::create(['code' => 'ASSIGN-A', 'name' => 'แผนกที่มอบหมาย']);
        $target = $this->userWithRole('learner');
        $target->update(['department_id' => $department->id]);
        $other = $this->userWithRole('learner');
        $due = now()->addWeek()->toDateString();
        $this->actingAs($admin)->post('/sessions/'.$session->id.'/assign', ['assignment_type' => 'department', 'target_id' => $department->id, 'due_date' => $due])->assertRedirect();
        $this->assertDatabaseHas('course_enrollments', ['user_id' => $target->id, 'state' => 'ASSIGNED', 'due_date' => $due]);
        $this->assertDatabaseMissing('course_enrollments', ['user_id' => $other->id]);
        $this->post('/sessions/'.$session->id.'/assign', ['assignment_type' => 'user', 'target_id' => $target->id])->assertRedirect();
        $this->assertSame(1, CourseEnrollment::count());
        $this->assertSame($due, CourseEnrollment::firstOrFail()->due_date->toDateString());
        $session->update(['enrollment_mode' => 'required']);
        $this->actingAs($other)->post('/sessions/'.$session->id.'/enroll')->assertForbidden();
    }

    public function test_published_content_is_frozen_and_clone_keeps_previous_session_version(): void
    {
        [$admin, $course, $session, $lesson] = $this->fixture();
        $video = $this->video($lesson);
        $this->actingAs($admin)->post('/modules/'.$lesson->module_id.'/lessons', ['title' => 'แก้ของเก่า', 'sort_order' => 1, 'is_required' => true, 'status' => 'active', 'lesson_id' => $lesson->id])->assertSessionHasErrors('course');
        $this->delete('/contents/'.$video->id)->assertSessionHasErrors('course');
        $this->post('/versions/'.$session->course_version_id.'/clone', ['version_name' => 'ทดสอบ 2'])->assertRedirect();
        $cloned = $course->versions()->where('version_number', 2)->firstOrFail();
        $this->assertNull($cloned->published_at);
        $this->assertSame($video->storage_path, $cloned->modules()->firstOrFail()->lessons()->firstOrFail()->contents()->firstOrFail()->storage_path);
        $this->assertSame($session->course_version_id, $session->fresh()->course_version_id);
        $this->assertNotSame($cloned->id, $session->course_version_id);
    }

    public function test_draft_upload_accepts_real_pdf_and_rejects_disguised_file(): void
    {
        [$admin, , $session, $lesson] = $this->fixture();
        $session->version->update(['published_at' => null]);
        Storage::fake('learning');
        $pdf = file_get_contents(base_path('demo-learn/วิชา ระเบียบวิธีวิจัย/เอกสารการเรียน ระเบียบวิจัย.pdf'));
        $fields = ['title' => 'เอกสารทดสอบ', 'content_type' => 'document', 'is_required' => true, 'sort_order' => 1];
        $this->actingAs($admin)->post('/lessons/'.$lesson->id.'/contents', [...$fields, 'file' => UploadedFile::fake()->createWithContent('เอกสาร.pdf', $pdf)])->assertRedirect()->assertSessionHasNoErrors();
        $content = $lesson->contents()->firstOrFail();
        $this->assertSame('application/pdf', $content->mime_type);
        Storage::disk('learning')->assertExists($content->storage_path);
        $this->post('/lessons/'.$lesson->id.'/contents', [...$fields, 'file' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo "unsafe";')])->assertSessionHasErrors('file');
        $this->assertSame(1, $lesson->contents()->count());
        $this->post('/versions/'.$session->course_version_id.'/publish')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($session->version->fresh()->published_at);
    }

    public function test_publish_rejects_empty_course_and_lesson_without_required_content(): void
    {
        [$admin, , $session, $lesson] = $this->fixture();
        $session->version->update(['published_at' => null]);
        $this->actingAs($admin)->post('/versions/'.$session->course_version_id.'/publish')->assertSessionHasErrors('course');
        $lesson->contents()->create(['title' => 'อ่านเพิ่มเติม', 'content_type' => 'article', 'body' => 'ข้อความ', 'is_required' => false]);
        $this->post('/versions/'.$session->course_version_id.'/publish')->assertSessionHasErrors('course');
    }

    public function test_document_requires_open_then_confirm_and_open_alone_does_not_complete(): void
    {
        [, , $session, $lesson] = $this->fixture();
        $learner = $this->userWithRole('learner');
        $enrollment = $this->enroll($learner, $session);
        $content = $lesson->contents()->create(['title' => 'บทความ', 'content_type' => 'article', 'body' => '<p>เนื้อหา</p>', 'is_required' => true]);
        $url = '/learning/'.$enrollment->id.'/contents/'.$content->id;
        $this->post($url.'/confirm')->assertSessionHasErrors('content');
        $this->postJson($url.'/open')->assertOk();
        $this->assertNull(LearningProgress::firstOrFail()->completed_at);
        $this->assertSame('LEARNING', $enrollment->fresh()->state);
        $this->post($url.'/confirm')->assertRedirect();
        $this->assertSame('COMPLETED', $enrollment->fresh()->state);
        $this->assertSame('100.00', $enrollment->fresh()->progress_percent);
        $this->post($url.'/confirm')->assertRedirect();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'learning.course_completed')->count());
    }

    public function test_video_watch_counts_unique_normal_playback_but_not_open_seek_replay_or_forged_time(): void
    {
        [, , $session, $lesson] = $this->fixture();
        $video = $this->video($lesson);
        $enrollment = $this->enroll($this->userWithRole('learner'), $session);
        $key = $this->beat($enrollment, $video, 0, begin: true)['key'];
        $this->assertNull(LearningProgress::firstOrFail()->completed_at);
        for ($step = 1; $step <= 4; $step++) {
            $this->travel(20)->seconds();
            $this->beat($enrollment, $video, $step * 20, $key);
        }
        $progress = LearningProgress::firstOrFail();
        $this->assertSame('80.00', $progress->watched_seconds);
        $this->assertNotNull($progress->completed_at);
        $this->assertSame('COMPLETED', $enrollment->fresh()->state);
        $key = $this->beat($enrollment, $video, 0, begin: true)['key'];
        $this->travel(20)->seconds();
        $this->beat($enrollment, $video, 20, $key);
        $this->assertSame('80.00', $progress->fresh()->watched_seconds);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['position' => 100, 'key' => $key, 'begin' => false])->assertUnprocessable(); // A seek is rejected, not saved as a new baseline.
        $this->assertSame('80.00', $progress->fresh()->watched_seconds);
        $this->post('/learning/'.$enrollment->id.'/contents/'.$video->id.'/confirm')->assertUnprocessable();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['position' => 1010, 'key' => $key, 'begin' => false])->assertUnprocessable();
    }

    public function test_rapid_heartbeats_cannot_credit_more_than_server_elapsed_time_and_other_tab_key_is_rejected(): void
    {
        [, , $session, $lesson] = $this->fixture();
        $video = $this->video($lesson);
        $enrollment = $this->enroll($this->userWithRole('learner'), $session);
        $this->travelTo(now()->startOfSecond());
        $key = $this->beat($enrollment, $video, 0, begin: true)['key'];
        for ($index = 1; $index <= 10; $index++) {
            $this->beat($enrollment, $video, $index, $key);
        }
        $this->assertSame('0.00', LearningProgress::firstOrFail()->watched_seconds);
        $newKey = $this->beat($enrollment, $video, 10, begin: true)['key'];
        $this->assertNotSame($key, $newKey);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['position' => 11, 'key' => $key, 'begin' => false])->assertConflict();
    }

    public function test_required_video_and_document_must_both_complete_and_posttest_gate_stops_final_completion(): void
    {
        [, , $session, $lesson] = $this->fixture();
        $session->update(['posttest_required' => true]);
        $video = $this->video($lesson);
        $article = $lesson->contents()->create(['title' => 'เอกสารประกอบ', 'content_type' => 'article', 'body' => '<p>เอกสาร</p>', 'is_required' => true]);
        $enrollment = $this->enroll($this->userWithRole('learner'), $session);
        $key = $this->beat($enrollment, $video, 0, begin: true)['key'];
        for ($step = 1; $step <= 4; $step++) {
            $this->travel(20)->seconds();
            $this->beat($enrollment, $video, $step * 20, $key);
        }
        $this->assertSame('0.00', $enrollment->fresh()->progress_percent);
        $base = '/learning/'.$enrollment->id.'/contents/'.$article->id;
        $this->postJson($base.'/open')->assertOk();
        $this->post($base.'/confirm')->assertRedirect();
        $this->assertSame('CONTENT_COMPLETED', $enrollment->fresh()->state);
        $this->assertNull($enrollment->fresh()->completed_at);
    }

    public function test_cross_enrollment_content_and_closed_or_cancelled_sessions_deny_learning(): void
    {
        [$admin, , $session, $lesson] = $this->fixture();
        $video = $this->video($lesson);
        $owner = $this->userWithRole('learner');
        $other = $this->userWithRole('learner');
        $enrollment = $this->enroll($owner, $session);
        $this->actingAs($other)->get('/learning/'.$enrollment->id)->assertForbidden();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['position' => 0, 'begin' => true])->assertForbidden();
        $this->get('/media/'.$video->id.'?enrollment='.$enrollment->id)->assertForbidden();
        $this->actingAs($admin)->post('/learning/'.$enrollment->id.'/cancel', ['reason' => 'ยกเลิกข้อมูลทดสอบ'])->assertRedirect();
        $this->actingAs($owner)->post('/sessions/'.$session->id.'/enroll')->assertForbidden();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['position' => 0, 'begin' => true])->assertForbidden();
        $session->update(['status' => 'closed']);
        $this->actingAs($other)->post('/sessions/'.$session->id.'/enroll')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'learning.enrollment_cancelled', 'reason' => 'ยกเลิกข้อมูลทดสอบ']);
    }

    public function test_content_from_a_different_version_is_rejected_and_pretest_gate_is_enforced(): void
    {
        [, $course, $session, $lesson] = $this->fixture();
        $version = $course->versions()->create(['version_number' => 2, 'version_name' => 'อื่น']);
        $module = $version->modules()->create(['title' => 'อื่น']);
        $otherLesson = $module->lessons()->create(['title' => 'อื่น']);
        $video = $this->video($otherLesson);
        $enrollment = $this->enroll($this->userWithRole('learner'), $session);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['position' => 0, 'begin' => true])->assertNotFound();
        $session->update(['pretest_required' => true]);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$this->video($lesson)->id.'/heartbeat', ['position' => 0, 'begin' => true])->assertConflict();
    }

    public function test_posttest_default_one_total_attempt_and_single_choice_scoring_contract(): void
    {
        [, , $session, $lesson] = $this->fixture();
        $enrollment = $this->enroll($this->userWithRole('learner'), $session);
        $exam = Exam::create(['course_version_id' => $session->course_version_id, 'exam_type' => 'POSTTEST', 'grading_mode' => 'SINGLE_CHOICE']);
        $rules = app(AssessmentRules::class);
        $this->assertSame(2.0, $rules->singleChoiceScore('A', 'A', 2));
        $this->assertSame(0.0, $rules->singleChoiceScore('B', 'A', 2));
        $this->assertSame(0.0, $rules->singleChoiceScore('A,B', 'A', 2));
        try {
            $rules->assertPosttestAllowed($enrollment, $exam);
            $this->fail('Content gate was skipped');
        } catch (ValidationException) {
        }
        $enrollment->update(['content_completed_at' => now()]);
        $rules->assertPosttestAllowed($enrollment->fresh(), $exam);
        DB::table('exam_attempts')->insert(['exam_id' => $exam->id, 'enrollment_id' => $enrollment->id, 'attempt_number' => 1, 'status' => 'IN_PROGRESS', 'started_at' => now(), 'rules_snapshot' => json_encode(['max_attempts' => 1]), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(ValidationException::class);
        $rules->assertPosttestAllowed($enrollment->fresh(), $exam);
    }

    public function test_article_html_sanitizer_preserves_formatting_and_removes_executable_elements_attributes(): void
    {
        $html = '<p onclick="alert(1)">อ่าน <strong style="color:red">เนื้อหา</strong><img src=x onerror=alert(1)></p><script>alert(2)</script><svg onload="alert(3)"></svg><iframe src="https://example.invalid"></iframe><a href="javascript:alert(4)">ลิงก์</a>';
        $clean = app(LearningHtml::class)->clean($html);
        $this->assertStringContainsString('<strong>เนื้อหา</strong>', $clean);
        foreach (['onclick', 'onerror', '<script', '<svg', '<iframe', 'javascript:', '<img', 'style='] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $clean);
        }
    }

    public function test_admin_can_open_and_confirm_document_when_learning_as_a_learner(): void
    {
        [$admin, , $session, $lesson] = $this->fixture();
        Storage::fake('learning');
        $path = 'media/aa/'.str_repeat('a', 64).'.pdf';
        Storage::disk('learning')->put($path, '%PDF-1.4 test');
        $document = $lesson->contents()->create(['title' => 'เอกสาร', 'content_type' => 'document', 'storage_path' => $path, 'original_name' => 'เอกสาร.pdf', 'mime_type' => 'application/pdf']);
        $enrollment = $this->enroll($admin, $session);
        $this->get('/media/'.$document->id.'?enrollment='.$enrollment->id)->assertOk()->assertHeader('X-Accel-Redirect', '/_protected_media/'.$path);
        $this->assertNotNull(LearningProgress::firstOrFail()->opened_at);
        $this->post('/learning/'.$enrollment->id.'/contents/'.$document->id.'/confirm')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', $enrollment->fresh()->state);
    }

    public function test_closed_session_page_keeps_history_but_does_not_send_article_body(): void
    {
        [, , $session, $lesson] = $this->fixture();
        $article = $lesson->contents()->create(['title' => 'บทความ', 'content_type' => 'article', 'body' => 'ข้อความห้ามเปิดก่อนผ่านเงื่อนไข']);
        $enrollment = $this->enroll($this->userWithRole('learner'), $session);
        $session->update(['status' => 'closed']);
        $this->get('/learning/'.$enrollment->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canLearn', false)->where('modules.0.lessons.0.contents.0.body', null));
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertForbidden();
    }

    public function test_real_mp4_metadata_is_measured_server_side_and_fake_mp4_is_rejected(): void
    {
        Storage::fake('learning');
        $media = app(LearningMediaService::class);
        $metadata = $media->store(base_path('demo-learn/วิชา ระเบียบวิธีวิจัย/บท1-3.mp4'), 'บท1-3.mp4', 'video');
        $this->assertSame(665, $metadata['duration_seconds']);
        $this->assertSame('video/mp4', $metadata['mime_type']);
        Storage::disk('learning')->assertExists($metadata['storage_path']);
        $file = UploadedFile::fake()->createWithContent('fake.mp4', 'not a video');
        $this->expectException(ValidationException::class);
        $media->store($file->getPathname(), 'fake.mp4', 'video');
    }
}
