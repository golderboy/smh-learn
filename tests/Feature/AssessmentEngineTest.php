<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonContent;
use App\Models\QuestionImport;
use App\Models\User;
use App\Services\QuestionImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

class AssessmentEngineTest extends FoundationTestCase
{
    /** @return array{User, User, CourseEnrollment, LessonContent} */
    private function fixture(int $attempts = 2): array
    {
        $admin = $this->userWithRole('administrator');
        $learner = $this->userWithRole('learner');
        $category = CourseCategory::create(['code' => 'ASSESSMENT', 'name' => 'สอบทดสอบ']);
        $course = Course::create(['category_id' => $category->id, 'code' => 'ASSESSMENT', 'title' => 'หลักสูตรสอบ', 'status' => 'published', 'created_by' => $admin->id]);
        $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ทดสอบ', 'published_at' => now()]);
        $module = $version->modules()->create(['title' => 'ทดสอบ']);
        $lesson = $module->lessons()->create(['title' => 'บทเรียน']);
        $article = $lesson->contents()->create(['title' => 'อ่าน', 'content_type' => 'article', 'body' => '<p>บทความทดสอบ</p>']);
        $session = $version->sessions()->create(['name' => 'สอบ', 'fiscal_year' => 2570, 'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => 'open', 'pretest_required' => true, 'posttest_required' => true, 'max_attempts' => $attempts]);
        $this->actingAs($admin)->post('/versions/'.$version->id.'/questions/preview', ['file' => new UploadedFile(base_path('docs/templates/objective-exam-template.xlsx'), 'questions.xlsx', null, null, true)])->assertRedirect();
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => QuestionImport::firstOrFail()->token])->assertRedirect();
        foreach (Exam::all() as $exam) {
            $this->put('/exams/'.$exam->id.'/settings', ['status' => 'published', 'randomize_questions' => true, 'randomize_choices' => true])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($learner)->post('/sessions/'.$session->id.'/enroll')->assertRedirect();

        return [$admin, $learner, CourseEnrollment::firstOrFail(), $article];
    }

    private function start(CourseEnrollment $enrollment, string $type): ExamAttempt
    {
        $this->post('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => $type])->assertRedirect()->assertSessionHasNoErrors();

        return ExamAttempt::latest('id')->firstOrFail();
    }

    /** @return list<array{question_id:int, selected_answer:string}> */
    private function answers(ExamAttempt $attempt, bool $correct): array
    {
        return DB::table('exam_answers')->where('attempt_id', $attempt->id)->get()->map(function ($row) use ($correct): array {
            $snapshot = json_decode($row->question_snapshot, true);

            return ['question_id' => $row->question_id, 'selected_answer' => $correct ? $snapshot['correct_answer'] : ($snapshot['correct_answer'] === 'A' ? 'B' : 'A')];
        })->all();
    }

    private function submit(ExamAttempt $attempt, bool $correct): void
    {
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => $attempt->fresh()->revision, 'answers' => $this->answers($attempt, $correct)])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function learn(CourseEnrollment $enrollment, LessonContent $article): void
    {
        $this->submit($this->start($enrollment, 'PRETEST'), false);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$article->id.'/confirm')->assertRedirect();
    }

    public function test_pretest_blocks_all_learning_and_zero_score_unlocks_after_complete_submission(): void
    {
        [, , $enrollment, $article] = $this->fixture();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertConflict();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$article->id.'/confirm')->assertConflict();
        $this->postJson('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'POSTTEST'])->assertUnprocessable();
        $attempt = $this->start($enrollment, 'PRETEST');
        $this->assertSame($attempt->id, $this->start($enrollment, 'PRETEST')->id);
        $this->get('/attempts/'.$attempt->id)->assertInertia(fn (AssertableInertia $page) => $page->component('Assessment/Exam')->missing('attempt.rules_snapshot')->missing('questions.0.correct_answer')->missing('questions.0.explanation')->has('questions', 2));
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'answers' => []])->assertSessionHasErrors();
        $this->submit($attempt, false);
        $this->assertSame('0.00', $attempt->fresh()->percentage);
        $this->assertNotNull($enrollment->fresh()->pretest_completed_at);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertOk();
        $this->postJson('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'PRETEST'])->assertUnprocessable();
    }

    public function test_posttest_requires_full_learning_then_one_zero_score_finishes_without_pass_gate(): void
    {
        [, , $enrollment, $article] = $this->fixture();
        $this->submit($this->start($enrollment, 'PRETEST'), false);
        $this->postJson('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'POSTTEST'])->assertUnprocessable();
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$article->id.'/confirm')->assertRedirect();
        $this->assertSame('CONTENT_COMPLETED', $enrollment->fresh()->state);
        $this->assertNull($enrollment->fresh()->completed_at);
        $attempt = $this->start($enrollment, 'POSTTEST');
        $this->assertNull($enrollment->fresh()->completed_at);
        $this->submit($attempt, false);
        $this->assertSame('COMPLETED', $enrollment->fresh()->state);
        $this->assertNotNull($enrollment->fresh()->posttest_completed_at);
        $this->assertNull($enrollment->fresh()->posttest_passed_at);
        $this->assertFalse($attempt->fresh()->passed);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'learning.course_completed')->count());
    }

    public function test_highest_result_survives_lower_retake_and_all_attempts_are_preserved_with_total_limit(): void
    {
        [, , $enrollment, $article] = $this->fixture(3);
        $this->learn($enrollment, $article);
        foreach ([false, true, false] as $correct) {
            $this->submit($this->start($enrollment, 'POSTTEST'), $correct);
        }
        $this->get('/learning/'.$enrollment->id)->assertInertia(fn (AssertableInertia $page) => $page->where('assessment.bestPosttest.percentage', '100.00')->where('assessment.usedPosttestAttempts', 3)->has('assessment.attempts', 4));
        $this->postJson('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'POSTTEST'])->assertUnprocessable();
        $this->assertSame(4, ExamAttempt::count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'learning.course_completed')->count());
    }

    public function test_default_one_total_attempt_resume_and_duplicate_submit_do_not_consume_or_overwrite(): void
    {
        [, , $enrollment, $article] = $this->fixture(1);
        $this->learn($enrollment, $article);
        $attempt = $this->start($enrollment, 'POSTTEST');
        $this->assertSame($attempt->id, $this->start($enrollment, 'POSTTEST')->id);
        $this->submit($attempt, true);
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'answers' => $this->answers($attempt, false)])->assertRedirect();
        $this->assertSame('100.00', $attempt->fresh()->percentage);
        $this->postJson('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'POSTTEST'])->assertUnprocessable();
        $this->assertSame(2, ExamAttempt::count());
    }

    public function test_autosave_resume_conflict_foreign_question_and_owner_checks(): void
    {
        [, , $enrollment] = $this->fixture();
        $attempt = $this->start($enrollment, 'PRETEST');
        $answers = $this->answers($attempt, true);
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => '0', 'answers' => [['question_id' => (string) $answers[0]['question_id'], 'selected_answer' => $answers[0]['selected_answer']]]])->assertOk()->assertJsonPath('attempt.revision', 1)->assertJsonMissingPath('attempt.rules_snapshot');
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'answers' => [['question_id' => $answers[0]['question_id']]]])->assertUnprocessable();
        $this->get('/learning/'.$enrollment->id)->assertInertia(fn (AssertableInertia $page) => $page->where('assessment.attempts.0.exam_type', 'PRETEST')->missing('assessment.attempts.0.rules_snapshot'));
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'answers' => $answers])->assertConflict();
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'answers' => [['question_id' => 99999, 'selected_answer' => 'A']]])->assertUnprocessable();
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'answers' => [['question_id' => $answers[0]['question_id'], 'selected_answer' => 'AB']]])->assertUnprocessable();
        $this->assertSame(1, $attempt->fresh()->revision);
        $this->assertSame($attempt->id, $this->start($enrollment, 'PRETEST')->id);
        $this->actingAs($this->userWithRole('learner'))->get('/attempts/'.$attempt->id)->assertForbidden();
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'answers' => $answers])->assertForbidden();
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 1, 'answers' => $answers])->assertForbidden();
    }

    public function test_six_choice_answers_autosave_resume_grade_from_snapshot_and_reject_nonexistent_choice(): void
    {
        [, , $enrollment, $article] = $this->fixture();
        $attempt = $this->start($enrollment, 'PRETEST');
        $answers = $this->answers($attempt, true);
        $four = collect($answers)->firstWhere('selected_answer', 'A');
        $six = collect($answers)->firstWhere('selected_answer', 'F');
        $this->assertNotNull($four);
        $this->assertNotNull($six);
        $this->get('/attempts/'.$attempt->id)->assertInertia(fn (AssertableInertia $page) => $page->missing('questions.0.correct_answer')->has('questions', 2));
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'answers' => [['question_id' => $four['question_id'], 'selected_answer' => 'F']]])->assertUnprocessable();
        $this->assertSame(0, $attempt->fresh()->revision);
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'answers' => [$six]])->assertOk()->assertJsonPath('attempt.revision', 1);
        $this->assertDatabaseHas('exam_answers', ['attempt_id' => $attempt->id, 'question_id' => $six['question_id'], 'selected_answer' => 'F']);
        $this->assertSame($attempt->id, $this->start($enrollment, 'PRETEST')->id);
        $this->submit($attempt, true);
        $this->assertSame('100.00', $attempt->fresh()->percentage);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$article->id.'/confirm')->assertRedirect();
        $post = $this->start($enrollment, 'POSTTEST');
        $postAnswers = $this->answers($post, true);
        $this->assertEqualsCanonicalizing(['E', 'F'], array_column($postAnswers, 'selected_answer'));
        $choiceOrders = DB::table('exam_answers')->where('attempt_id', $post->id)->get()->map(function ($row) {
            $snapshot = json_decode($row->question_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->assertEqualsCanonicalizing(array_keys($snapshot['choices']), $snapshot['choice_order']);

            return count($snapshot['choice_order']);
        })->sort()->values()->all();
        $this->assertSame([5, 6], $choiceOrders);
        DB::table('question_choices')->whereIn('choice_key', ['E', 'F'])->update(['is_correct' => false, 'choice_text' => 'เปลี่ยนหลังเริ่มสอบ']);
        $this->submit($post, true);
        $this->assertSame('100.00', $post->fresh()->percentage);
        $this->assertSame('COMPLETED', $enrollment->fresh()->state);
    }

    public function test_expiry_grades_saved_answers_only_and_late_answers_cannot_change_results(): void
    {
        [, , $enrollment] = $this->fixture();
        Exam::where('exam_type', 'PRETEST')->update(['time_limit_minutes' => 1]);
        $attempt = $this->start($enrollment, 'PRETEST');
        $answers = $this->answers($attempt, true);
        $onePoint = DB::table('exam_answers')->where('attempt_id', $attempt->id)->get()->first(fn ($row) => json_decode($row->question_snapshot, true)['max_score'] === 1)->question_id;
        $answers = collect($answers)->sortBy(fn ($row) => $row['question_id'] === $onePoint ? 0 : 1)->values()->all();
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 0, 'answers' => [$answers[0]]])->assertOk();
        $this->travel(61)->seconds();
        $this->postJson('/attempts/'.$attempt->id.'/answers', ['revision' => 1, 'answers' => $answers])->assertOk()->assertJsonPath('attempt.status', 'EXPIRED');
        $this->assertSame('33.33', $attempt->fresh()->percentage);
        $this->assertNotNull($enrollment->fresh()->pretest_completed_at);
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 1, 'answers' => $answers])->assertRedirect();
        $this->assertSame('33.33', $attempt->fresh()->percentage);
    }

    public function test_attempt_uses_frozen_answer_and_rules_not_client_score_or_later_bank_changes(): void
    {
        [, , $enrollment] = $this->fixture();
        $attempt = $this->start($enrollment, 'PRETEST');
        DB::table('question_choices')->update(['is_correct' => false]);
        DB::table('questions')->update(['max_score' => 100]);
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'answers' => $this->answers($attempt, true), 'score' => 999, 'max_score' => 999, 'percentage' => 0])->assertRedirect();
        $this->assertSame('3.00', $attempt->fresh()->score);
        $this->assertSame('3.00', $attempt->fresh()->max_score);
        $this->assertSame('100.00', $attempt->fresh()->percentage);
    }

    public function test_instructor_attempt_limit_is_scoped_and_can_increase_without_losing_history(): void
    {
        [$admin, $learner, $enrollment, $article] = $this->fixture(1);
        $instructor = $this->userWithRole('instructor');
        $this->actingAs($instructor)->put('/sessions/'.$enrollment->course_session_id.'/assessment', ['max_attempts' => 2])->assertForbidden();
        $instructor->supervisedCategories()->attach($enrollment->session->version->course->category_id);
        $this->put('/sessions/'.$enrollment->course_session_id.'/assessment', ['max_attempts' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($learner);
        $this->learn($enrollment, $article);
        $this->submit($this->start($enrollment, 'POSTTEST'), false);
        $this->submit($this->start($enrollment, 'POSTTEST'), true);
        $this->actingAs($instructor)->put('/sessions/'.$enrollment->course_session_id.'/assessment', ['max_attempts' => 1])->assertSessionHasErrors('max_attempts');
        $this->put('/sessions/'.$enrollment->course_session_id.'/assessment', ['max_attempts' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $exam = Exam::where('exam_type', 'POSTTEST')->firstOrFail();
        $this->actingAs($admin)->put('/exams/'.$exam->id.'/settings', ['status' => 'draft', 'randomize_questions' => false, 'randomize_choices' => false])->assertSessionHasErrors('exam');
        $this->actingAs($learner);
        $this->assertSame(3, $this->start($enrollment, 'POSTTEST')->attempt_number);
    }

    public function test_supplied_workbook_rejects_duplicate_codes_and_normalized_copy_is_valid_fifty_questions(): void
    {
        $service = app(QuestionImportService::class);
        $rows = $service->parse(base_path('demo-learn/objective-exam-import1-normalized.xlsx'));
        $this->assertCount(50, $rows);
        $this->assertSame(20, collect($rows)->where('usage', 'PRETEST')->count());
        $this->assertSame(30, collect($rows)->where('usage', 'POSTTEST')->count());
        $this->assertSame('PRE020', $rows[19]['code']);
        $this->assertSame('POST030', $rows[49]['code']);
        $this->expectException(ValidationException::class);
        $service->parse(base_path('demo-learn/objective-exam-import1.xlsx'));
    }

    public function test_document_pairing_is_same_version_scoped_and_clone_keeps_links_and_draft_exams(): void
    {
        [$admin, $learner, $enrollment, $article] = $this->fixture();
        $course = $enrollment->session->version->course;
        $pdf = $article->lesson->contents()->create(['title' => 'PDF ประกอบ', 'content_type' => 'document', 'original_name' => 'test.pdf']);
        $url = '/lessons/'.$article->lesson_id.'/documents';
        $enrollment->session->version->update(['published_at' => null]);
        $this->actingAs($learner)->put($url, ['content_ids' => [$pdf->id]])->assertForbidden();
        $this->actingAs($admin)->put($url, ['content_ids' => [999999]])->assertSessionHasErrors('documents');
        $this->assertDatabaseCount('lesson_document_links', 0);
        $this->put($url, ['content_ids' => [$pdf->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lesson_document_links', ['lesson_id' => $article->lesson_id, 'content_id' => $pdf->id]);
        $enrollment->session->version->update(['published_at' => now()]);
        $this->put($url, ['content_ids' => []])->assertSessionHasErrors('course');
        $this->post('/versions/'.$enrollment->session->course_version_id.'/clone', ['version_name' => 'ใหม่'])->assertRedirect();
        $version = $course->versions()->where('version_number', 2)->firstOrFail();
        $newLesson = $version->modules()->firstOrFail()->lessons()->firstOrFail();
        $newPdf = $newLesson->contents()->where('content_type', 'document')->firstOrFail();
        $this->assertDatabaseHas('lesson_document_links', ['lesson_id' => $newLesson->id, 'content_id' => $newPdf->id]);
        $this->assertSame(2, $version->exams()->where('status', 'draft')->count());
        $this->assertSame(4, DB::table('exam_questions')->whereIn('exam_id', $version->exams()->pluck('id'))->count());
        $foreign = $newPdf->id;
        $this->put('/lessons/'.$newLesson->id.'/documents', ['content_ids' => [$pdf->id]])->assertSessionHasErrors('documents');
    }

    public function test_video_start_cannot_jump_ahead_and_failed_seek_preserves_last_position(): void
    {
        [, , $enrollment, $article] = $this->fixture();
        $this->submit($this->start($enrollment, 'PRETEST'), false);
        $video = $article->lesson->contents()->create(['title' => 'คลิป', 'content_type' => 'video', 'duration_seconds' => 100]);
        $url = '/learning/'.$enrollment->id.'/contents/'.$video->id.'/heartbeat';
        $this->postJson($url, ['position' => 70, 'begin' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('learning_progress', ['enrollment_id' => $enrollment->id, 'content_id' => $video->id]);
        $key = $this->postJson($url, ['position' => 0, 'begin' => true])->assertOk()->json('key');
        $this->travel(5)->seconds();
        $this->postJson($url, ['position' => 5, 'begin' => false, 'key' => $key])->assertOk();
        $this->postJson($url, ['position' => 70, 'begin' => false, 'key' => $key])->assertUnprocessable();
        $this->assertDatabaseHas('learning_progress', ['enrollment_id' => $enrollment->id, 'content_id' => $video->id, 'last_position_seconds' => '5.00', 'watched_seconds' => '5.00']);
    }
}
