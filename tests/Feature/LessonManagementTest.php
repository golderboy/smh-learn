<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\LearningProgress;
use App\Services\ExternalVideo;
use App\Services\LearningMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

class LessonManagementTest extends FoundationTestCase
{
    private function fixture(): array
    {
        $admin = $this->userWithRole('administrator');
        $category = CourseCategory::create(['code' => 'LESSON-MANAGEMENT', 'name' => 'หมวดทดสอบบท']);
        $course = Course::create(['category_id' => $category->id, 'code' => 'LESSON-MANAGEMENT', 'title' => 'ทดสอบลำดับบท', 'status' => 'published', 'created_by' => $admin->id]);
        $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ทดสอบ', 'published_at' => now()]);
        $module = $version->modules()->create(['title' => 'หมวดบท']);
        $first = $module->lessons()->create(['title' => 'บท 1', 'sort_order' => 1]);
        $second = $module->lessons()->create(['title' => 'บท 2', 'sort_order' => 2]);
        $article = $first->contents()->create(['title' => 'บทความ 1', 'content_type' => 'article', 'body' => '<p>อ่านบท 1</p>']);
        $later = $second->contents()->create(['title' => 'บทความ 2', 'content_type' => 'article', 'body' => '<p>อ่านบท 2</p>']);
        $session = $version->sessions()->create(['name' => 'รอบทดสอบ', 'fiscal_year' => 2570, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'status' => 'open', 'pretest_required' => true, 'posttest_required' => true]);
        $learner = $this->userWithRole('learner');
        $enrollment = CourseEnrollment::create(['user_id' => $learner->id, 'course_session_id' => $session->id, 'enrolled_at' => now()]);

        return [$admin, $course, $module, $first, $second, $article, $later, $learner, $enrollment];
    }

    public function test_pretest_download_exception_does_not_unlock_or_acknowledge_future_lessons(): void
    {
        [, $course, $module, $first, $second, $article, $later, $learner, $enrollment] = $this->fixture();
        Storage::fake('learning');
        $path = 'media/aa/'.str_repeat('a', 64).'.pdf';
        Storage::disk('learning')->put($path, '%PDF-1.4 test');
        $source = $module->lessons()->create(['title' => 'แหล่ง PDF เดิม', 'sort_order' => 3]);
        $pdf = $source->contents()->create(['title' => 'PDF คู่บท 1', 'content_type' => 'document', 'storage_path' => $path, 'mime_type' => 'application/pdf', 'original_name' => 'test.pdf']);
        $first->documents()->attach($pdf->id, ['sort_order' => 1]);
        $second->documents()->attach($pdf->id, ['sort_order' => 1]);
        $this->actingAs($learner)->get('/media/'.$pdf->id.'?enrollment='.$enrollment->id.'&download=1')->assertStatus(409);
        $this->get('/courses/'.$course->id)->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 0));
        $enrollment->update(['pretest_completed_at' => now()]);
        $this->get('/courses/'.$course->id)->assertInertia(fn (AssertableInertia $page) => $page->has('documents.'.$enrollment->id, 1));
        $this->get('/learning/'.$enrollment->id)->assertInertia(fn (AssertableInertia $page) => $page->has('modules.0.lessons', 2)->where('modules.0.lessons.1.contents.0.body', null));
        $this->get('/learning/'.$enrollment->id.'?lesson='.$second->id)->assertStatus(409);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$later->id.'/open')->assertStatus(409);
        $this->post('/learning/'.$enrollment->id.'/contents/'.$later->id.'/confirm')->assertStatus(409);
        $response = $this->get('/media/'.$pdf->id.'?enrollment='.$enrollment->id.'&download=1')->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''test.pdf", $response->headers->get('Content-Disposition'));
        $this->assertSame(0, LearningProgress::count());
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$article->id.'/confirm')->assertRedirect();
        $this->assertSame('0.00', $enrollment->fresh()->progress_percent);
        $this->get('/learning/'.$enrollment->id.'?lesson='.$second->id)->assertStatus(409);
        $this->get('/media/'.$pdf->id.'?enrollment='.$enrollment->id)->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$pdf->id.'/confirm')->assertRedirect();
        $this->assertSame('50.00', $enrollment->fresh()->progress_percent);
        $this->get('/learning/'.$enrollment->id.'?lesson='.$second->id)->assertOk();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$later->id.'/open')->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$later->id.'/confirm')->assertRedirect();
        $this->assertSame('CONTENT_COMPLETED', $enrollment->fresh()->state);
        $this->assertNull($enrollment->fresh()->completed_at);
        $this->get('/learning/'.$enrollment->id.'?lesson='.$first->id)->assertOk();
        $enrollment->update(['cancelled_at' => now()]);
        $this->get('/media/'.$pdf->id.'?enrollment='.$enrollment->id.'&download=1')->assertForbidden();
    }

    public function test_future_file_and_video_heartbeat_cannot_bypass_sequence_or_version_scope(): void
    {
        [, , , , $second, , , $learner, $enrollment] = $this->fixture();
        $video = $second->contents()->create(['title' => 'MP4 บท 2', 'content_type' => 'video', 'duration_seconds' => 10]);
        $enrollment->update(['pretest_completed_at' => now()]);
        $this->actingAs($learner)->postJson('/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat', ['begin' => true, 'position' => 0])->assertStatus(409);
        $this->get('/media/'.$video->id.'?enrollment='.$enrollment->id.'&download=1')->assertStatus(409);
        $this->assertSame(0, LearningProgress::count());
        $other = $this->userWithRole('learner');
        $this->actingAs($other)->get('/learning/'.$enrollment->id)->assertForbidden();
    }

    public function test_scoped_author_can_choose_lesson_count_images_and_external_video_without_attachments(): void
    {
        [$admin, $course, $module, $first] = $this->fixture();
        $version = $module->version;
        $version->update(['published_at' => null]);
        $instructor = $this->userWithRole('instructor');
        $this->actingAs($instructor)->delete('/lessons/'.$first->id)->assertForbidden();
        $instructor->supervisedCategories()->attach($course->category_id);
        $this->post('/modules/'.$module->id.'/lessons', ['title' => 'บทที่ Instructor เพิ่ม', 'sort_order' => 3, 'is_required' => true, 'status' => 'active'])->assertRedirect();
        $lesson = $module->lessons()->where('title', 'บทที่ Instructor เพิ่ม')->firstOrFail();
        $data = ['title' => 'YouTube ไม่มีเอกสาร', 'content_type' => 'video', 'video_source' => 'youtube', 'external_url' => 'https://youtu.be/M7lc1UVf-VE?t=10', 'duration_seconds' => 270, 'is_required' => true, 'sort_order' => 1];
        $this->post('/lessons/'.$lesson->id.'/contents', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lesson_contents', ['lesson_id' => $lesson->id, 'video_source' => 'youtube', 'video_id' => 'M7lc1UVf-VE', 'external_url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE']);
        $this->assertSame(1, $lesson->contents()->count());
        Storage::fake('learning');
        $image = UploadedFile::fake()->createWithContent('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1sAAAAASUVORK5CYII='));
        $this->post('/lessons/'.$lesson->id.'/contents', ['title' => 'ภาพประกอบ', 'content_type' => 'image', 'is_required' => false, 'sort_order' => 2, 'file' => $image])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lesson_contents', ['lesson_id' => $lesson->id, 'content_type' => 'image', 'mime_type' => 'image/png']);
        $this->post('/versions/'.$version->id.'/publish')->assertRedirect()->assertSessionHasNoErrors();
        $this->delete('/lessons/'.$lesson->id)->assertSessionHasErrors('course');
        $this->actingAs($admin)->post('/versions/'.$version->id.'/clone', ['version_name' => 'แก้จำนวนบท'])->assertRedirect();
        $draft = $course->versions()->orderByDesc('id')->firstOrFail();
        $copy = $draft->modules()->firstOrFail()->lessons()->where('title', $lesson->title)->firstOrFail();
        $this->delete('/lessons/'.$copy->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
        $this->assertDatabaseMissing('lessons', ['id' => $copy->id]);
    }

    public function test_external_urls_are_validated_and_actual_duration_is_required_for_credit(): void
    {
        $parser = app(ExternalVideo::class);
        foreach (['https://www.youtube.com/watch?v=M7lc1UVf-VE', 'https://youtube.com/shorts/M7lc1UVf-VE', 'https://www.youtube.com/embed/M7lc1UVf-VE', 'https://youtu.be/M7lc1UVf-VE'] as $url) {
            $this->assertSame('M7lc1UVf-VE', $parser->parse($url, 'youtube')['video_id']);
        }
        $this->assertSame('remote', $parser->parse('https://example.com/movie.mp4?token=fixture', 'remote')['video_source']);
        foreach (['https://youtube.com.attacker.test/watch?v=M7lc1UVf-VE', 'https://user:pass@youtube.com/watch?v=M7lc1UVf-VE', 'http://youtu.be/M7lc1UVf-VE', 'https://youtu.be/bad', 'https://youtube.com/playlist?list=bad'] as $url) {
            try {
                $parser->parse($url, 'youtube');
                $this->fail('Invalid URL accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        [, , , $first, , , , $learner, $enrollment] = $this->fixture();
        $content = $first->contents()->create(['title' => 'MP4 URL', 'content_type' => 'video', 'video_source' => 'remote', 'external_url' => 'https://example.com/movie.mp4', 'duration_seconds' => 10]);
        $enrollment->update(['pretest_completed_at' => now()]);
        $this->actingAs($learner)->postJson('/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat', ['begin' => true, 'position' => 0])->assertStatus(409);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat', ['begin' => true, 'position' => 0, 'duration' => 5])->assertStatus(409);
        $result = $this->postJson('/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat', ['begin' => true, 'position' => 0, 'duration' => 9.8])->assertOk()->json();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat', ['begin' => false, 'position' => 9, 'key' => $result['key'], 'duration' => 9.8])->assertStatus(422);
        $this->assertNull(LearningProgress::where('content_id', $content->id)->firstOrFail()->completed_at);
    }

    public function test_fractional_video_duration_reaches_one_hundred_percent_and_replay_does_not_double_credit(): void
    {
        [, , , $first, , , , $learner, $enrollment] = $this->fixture();
        $content = $first->contents()->create(['title' => 'คลิปสั้นมีเศษวินาที', 'content_type' => 'video', 'video_source' => 'remote', 'external_url' => 'https://example.com/movie.mp4', 'duration_seconds' => 6]);
        $enrollment->update(['pretest_completed_at' => now()]);
        $enrollment->session->update(['required_video_watch_percent' => 100]);
        $this->actingAs($learner);
        $url = '/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat';
        for ($round = 0; $round < 2; $round++) {
            $key = $this->postJson($url, ['begin' => true, 'position' => 0, 'duration' => 5.055])->assertOk()->json('key');
            $this->travel(6)->seconds();
            $response = $this->postJson($url, ['begin' => false, 'position' => 5.055, 'duration' => 5.055, 'key' => $key])->assertOk();
            $response->assertJsonPath('progress.watch_percent', '100.00');
            $this->assertNotNull($response->json('progress.completed_at'));
            $this->assertEqualsWithDelta(5.055, (float) $response->json('progress.watched_seconds'), 0.01);
        }
    }

    public function test_actual_end_can_finish_a_hundred_percent_rule_with_bounded_clock_jitter_but_forged_early_end_cannot(): void
    {
        $this->freezeSecond();
        [, , , $first, , , , $learner, $enrollment] = $this->fixture();
        $content = $first->contents()->create(['title' => 'คลิป 100%', 'content_type' => 'video', 'video_source' => 'remote', 'external_url' => 'https://example.com/movie.mp4', 'duration_seconds' => 6]);
        $enrollment->update(['pretest_completed_at' => now()]);
        $enrollment->session->update(['required_video_watch_percent' => 100]);
        $url = '/learning/'.$enrollment->id.'/contents/'.$content->id.'/heartbeat';
        $this->actingAs($learner);
        $key = $this->postJson($url, ['begin' => true, 'position' => 0.01, 'duration' => 5.055])->assertOk()->json('key');
        $this->travel(5)->seconds();
        $this->postJson($url, ['begin' => false, 'position' => 5.055, 'duration' => 5.055, 'key' => $key])->assertOk();
        $this->assertNull(LearningProgress::where('content_id', $content->id)->firstOrFail()->completed_at);
        $response = $this->postJson($url, ['begin' => false, 'position' => 5.055, 'duration' => 5.055, 'key' => $key, 'ended' => true])->assertOk();
        $response->assertJsonPath('progress.watch_percent', '100.00');
        $this->assertNotNull($response->json('progress.completed_at'));
        $this->assertLessThanOrEqual(5, (float) $response->json('progress.watched_seconds'));
        $other = $first->contents()->create(['title' => 'คลิปลองปลอมจบ', 'content_type' => 'video', 'video_source' => 'remote', 'external_url' => 'https://example.com/movie.mp4', 'duration_seconds' => 6]);
        $url = '/learning/'.$enrollment->id.'/contents/'.$other->id.'/heartbeat';
        $key = $this->postJson($url, ['begin' => true, 'position' => 0, 'duration' => 5.055])->assertOk()->json('key');
        $this->travel(1)->seconds();
        $this->postJson($url, ['begin' => false, 'position' => 5.055, 'duration' => 5.055, 'key' => $key, 'ended' => true])->assertStatus(422);
        $this->assertNull(LearningProgress::where('content_id', $other->id)->firstOrFail()->completed_at);
    }

    public function test_publish_rejects_an_inactive_source_of_a_paired_required_document(): void
    {
        [$admin, , $module, $first, $second] = $this->fixture();
        $version = $module->version;
        $version->update(['published_at' => null]);
        $document = $second->contents()->create(['title' => 'เอกสารคู่บท', 'content_type' => 'document']);
        $first->documents()->attach($document->id, ['sort_order' => 1]);
        $second->update(['status' => 'inactive']);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/publish')->assertSessionHasErrors('course');
        $this->assertNull($version->fresh()->published_at);
        $first->documents()->detach();
        $this->post('/versions/'.$version->id.'/publish')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($version->fresh()->published_at);
    }

    public function test_image_upload_rejects_disguised_scripts_and_svg(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'smh-image-');
        file_put_contents($source, '<?php echo "script";');
        try {
            app(LearningMediaService::class)->store($source, 'image.png', 'image');
            $this->fail('Disguised script accepted');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        } finally {
            unlink($source);
        }
        $this->expectException(ValidationException::class);
        $file = UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        app(LearningMediaService::class)->store($file->getPathname(), 'image.svg', 'image');
    }
}
