<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonContent;
use App\Models\PointRule;
use App\Models\PointTransaction;
use App\Models\QuestionImport;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PointService;
use App\Services\ReportFileWriter;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use OpenSpout\Reader\XLSX\Reader;

class ReportingPointsTest extends FoundationTestCase
{
    /** @return array{User,User,CourseEnrollment,LessonContent} */
    private function fixture(): array
    {
        $admin = $this->userWithRole('administrator');
        $learner = $this->userWithRole('learner');
        $department = Department::create(['code' => 'HISTORY', 'name' => 'แผนกเดิม']);
        $learner->update(['department_id' => $department->id, 'employee_code' => '00123']);
        $category = CourseCategory::create(['code' => 'REPORTS', 'name' => 'หมวดรายงาน']);
        $course = Course::create(['category_id' => $category->id, 'code' => 'REPORTS', 'title' => 'หลักสูตรรายงาน', 'status' => 'published', 'created_by' => $admin->id]);
        $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ทดสอบ', 'published_at' => now()]);
        $module = $version->modules()->create(['title' => 'ทดสอบ']);
        $lesson = $module->lessons()->create(['title' => 'อ่าน']);
        $article = $lesson->contents()->create(['title' => 'บทความ', 'content_type' => 'article', 'body' => '<p>อ่านแล้วทำข้อสอบ</p>']);
        $session = $version->sessions()->create(['name' => 'รอบรายงาน', 'fiscal_year' => 2570, 'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => 'open', 'pretest_required' => true, 'posttest_required' => true, 'max_attempts' => 3]);
        $this->actingAs($admin)->put('/sessions/'.$session->id.'/points', ['points_enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/versions/'.$version->id.'/questions/preview', ['file' => new UploadedFile(base_path('docs/templates/objective-exam-template.xlsx'), 'questions.xlsx', null, null, true)])->assertRedirect();
        $this->post('/versions/'.$version->id.'/questions/commit', ['token' => QuestionImport::firstOrFail()->token])->assertRedirect();
        foreach (Exam::all() as $exam) {
            $this->put('/exams/'.$exam->id.'/settings', ['status' => 'published', 'randomize_questions' => true, 'randomize_choices' => true])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($learner)->post('/sessions/'.$session->id.'/enroll')->assertRedirect();

        return [$admin, $learner, CourseEnrollment::firstOrFail(), $article];
    }

    private function submit(CourseEnrollment $enrollment, string $type, bool $correct): ExamAttempt
    {
        $this->post('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => $type])->assertRedirect()->assertSessionHasNoErrors();
        $attempt = ExamAttempt::latest('id')->firstOrFail();
        $answers = DB::table('exam_answers')->where('attempt_id', $attempt->id)->get()->map(function ($answer) use ($correct): array {
            $snapshot = json_decode($answer->question_snapshot, true);

            return ['question_id' => $answer->question_id, 'selected_answer' => $correct ? $snapshot['correct_answer'] : ($snapshot['correct_answer'] === 'A' ? 'B' : 'A')];
        })->all();
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'answers' => $answers])->assertRedirect()->assertSessionHasNoErrors();

        return $attempt->fresh();
    }

    private function learn(CourseEnrollment $enrollment, LessonContent $article): void
    {
        $this->submit($enrollment, 'PRETEST', false);
        $this->postJson('/learning/'.$enrollment->id.'/contents/'.$article->id.'/open')->assertOk();
        $this->post('/learning/'.$enrollment->id.'/contents/'.$article->id.'/confirm')->assertRedirect();
    }

    public function test_completion_zero_score_then_highest_retake_awards_once_and_freezes_rules(): void
    {
        [, $learner, $enrollment, $article] = $this->fixture();
        $this->learn($enrollment, $article);
        $this->assertSame(0, PointTransaction::count());
        $this->submit($enrollment, 'POSTTEST', false);
        $this->assertSame('COMPLETED', $enrollment->fresh()->state);
        $this->assertSame(20, app(PointService::class)->balance($learner));
        PointRule::where('code', 'PASS_POSTTEST')->update(['points' => 999]);
        $best = $this->submit($enrollment, 'POSTTEST', true);
        $this->assertSame(60, app(PointService::class)->balance($learner));
        $this->post('/attempts/'.$best->id.'/submit', ['revision' => 0, 'answers' => []])->assertRedirect();
        $this->submit($enrollment, 'POSTTEST', false);
        $this->assertSame(60, app(PointService::class)->balance($learner));
        $this->assertSame(3, PointTransaction::count());
        $report = app(ReportingService::class)->report($learner, 'individual');
        $this->assertSame(100.0, $report['rows'][0]['posttest']);
        $this->assertSame(100.0, $report['rows'][0]['gain']);
        $this->assertSame(3, $report['rows'][0]['attempts']);
        $this->assertSame(60, $report['rows'][0]['points']);
    }

    public function test_second_round_of_same_course_can_award_again(): void
    {
        [$admin, $learner, $enrollment, $article] = $this->fixture();
        $this->learn($enrollment, $article);
        $this->submit($enrollment, 'POSTTEST', true);
        $session = $enrollment->session->replicate();
        $session->name = 'รอบใหม่';
        $session->save();
        $this->actingAs($learner)->post('/sessions/'.$session->id.'/enroll')->assertRedirect();
        $next = CourseEnrollment::latest('id')->firstOrFail();
        $this->learn($next, $article);
        $this->submit($next, 'POSTTEST', true);
        $this->assertSame(120, app(PointService::class)->balance($learner));
        $this->assertSame(6, PointTransaction::count());
        $this->actingAs($admin)->put('/sessions/'.$session->id.'/points', ['points_enabled' => false])->assertSessionHasErrors('points');
    }

    public function test_passing_eighty_percent_awards_fifty_then_ninety_bonus_requires_higher_result(): void
    {
        [, $learner, $enrollment, $article] = $this->fixture();
        $this->learn($enrollment, $article);
        $exam = Exam::where('exam_type', 'POSTTEST')->firstOrFail();
        foreach (DB::table('exam_questions')->where('exam_id', $exam->id)->orderBy('question_id')->get() as $index => $question) {
            $snapshot = json_decode($question->question_snapshot, true);
            $snapshot['max_score'] = $index === 0 ? 4 : 1;
            DB::table('exam_questions')->where('exam_id', $exam->id)->where('question_id', $question->question_id)->update(['question_snapshot' => json_encode($snapshot)]);
        }
        $this->post('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'POSTTEST'])->assertRedirect();
        $attempt = ExamAttempt::latest('id')->firstOrFail();
        $answers = DB::table('exam_answers')->where('attempt_id', $attempt->id)->get()->map(function ($answer): array {
            $snapshot = json_decode($answer->question_snapshot, true);

            return ['question_id' => $answer->question_id, 'selected_answer' => $snapshot['max_score'] === 4 ? $snapshot['correct_answer'] : ($snapshot['correct_answer'] === 'A' ? 'B' : 'A')];
        })->all();
        $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'answers' => $answers])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('80.00', $attempt->fresh()->percentage);
        $this->assertSame(50, app(PointService::class)->balance($learner));
        $this->assertSame(0, PointTransaction::where('transaction_type', 'HIGH_SCORE')->count());
        $this->submit($enrollment, 'POSTTEST', true);
        $this->assertSame(60, app(PointService::class)->balance($learner));
    }

    public function test_completion_and_points_roll_back_together_when_ledger_write_fails(): void
    {
        [, , $enrollment, $article] = $this->fixture();
        $this->learn($enrollment, $article);
        PointTransaction::creating(fn () => throw new \RuntimeException('Injected ledger storage failure'));
        try {
            $this->post('/learning/'.$enrollment->id.'/exams/start', ['exam_type' => 'POSTTEST'])->assertRedirect();
            $attempt = ExamAttempt::latest('id')->firstOrFail();
            $answers = DB::table('exam_answers')->where('attempt_id', $attempt->id)->get()->map(fn ($row): array => ['question_id' => $row->question_id, 'selected_answer' => 'A'])->all();
            $this->post('/attempts/'.$attempt->id.'/submit', ['revision' => 0, 'answers' => $answers])->assertStatus(500);
            $this->assertNull($attempt->fresh()->graded_at);
            $this->assertNull($enrollment->fresh()->completed_at);
            $this->assertSame(0, PointTransaction::count());
        } finally {
            PointTransaction::clearBootedModels();
            PointTransaction::flushEventListeners();
        }
    }

    public function test_reports_scope_uses_enrollment_history_after_staff_move(): void
    {
        [$admin, $learner, $enrollment] = $this->fixture();
        $oldId = $learner->department_id;
        $new = Department::create(['code' => 'NEW', 'name' => 'แผนกใหม่']);
        $learner->update(['department_id' => $new->id]);
        $oldManager = $this->userWithRole('department-manager');
        $oldManager->update(['department_id' => $oldId]);
        $newManager = $this->userWithRole('department-manager');
        $newManager->update(['department_id' => $new->id]);
        $instructor = $this->userWithRole('instructor');
        $service = app(ReportingService::class);
        $this->assertCount(1, $service->individuals($oldManager));
        $this->assertCount(0, $service->individuals($newManager));
        $this->assertCount(0, $service->individuals($instructor));
        $instructor->supervisedCategories()->attach($enrollment->session->version->course->category_id);
        $this->assertCount(1, $service->individuals($instructor));
        $other = $this->userWithRole('learner');
        $this->assertCount(0, $service->individuals($other, ['user_id' => $learner->id]));
        $this->actingAs($learner)->get('/reports')->assertInertia(fn (AssertableInertia $page) => $page->where('report.rows.data.0.department', 'แผนกเดิม')->missing('report.rows.data.0.username')->missing('report.rows.data.0.correct_answer'));
        $this->get('/reports?type=question')->assertForbidden();
        $this->get('/admin/points')->assertForbidden();
        $this->actingAs($admin)->get('/reports?type=question')->assertOk();
        $learner->roles()->attach(Role::where('code', 'instructor')->firstOrFail()->id);
        $this->assertCount(0, $service->individuals($learner));
        $this->assertCount(1, $service->individuals($learner, ['audience' => 'mine']));
    }

    public function test_report_filters_half_open_dates_fiscal_year_and_zero_vs_absent(): void
    {
        [$admin, $learner, $enrollment, $article] = $this->fixture();
        $service = app(ReportingService::class);
        $this->assertNull($service->report($admin, 'individual')['summary']['pretest_average']);
        $this->learn($enrollment, $article);
        $report = $service->report($admin, 'individual');
        $this->assertSame(0.0, $report['summary']['pretest_average']);
        $this->assertNull($report['summary']['gain_average']);
        $date = now()->toDateString();
        $this->assertCount(1, $service->individuals($admin, ['date_from' => $date, 'date_to' => $date, 'fiscal_year' => 2570]));
        $this->assertCount(0, $service->individuals($admin, ['date_to' => now()->subDay()->toDateString()]));
        $this->assertCount(0, $service->individuals($admin, ['fiscal_year' => 2569]));
        $this->actingAs($admin)->get('/reports?date_to='.$date)->assertOk();
        $enrollment->update(['cancelled_at' => now(), 'state' => 'CANCELLED']);
        $report = $service->report($admin, 'department');
        $this->assertSame(0, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['cancelled']);
        $this->assertNull($report['summary']['pretest_average']);
    }

    public function test_question_analytics_uses_frozen_snapshot_and_all_graded_attempts(): void
    {
        [$admin, , $enrollment, $article] = $this->fixture();
        $this->learn($enrollment, $article);
        $this->submit($enrollment, 'POSTTEST', true);
        $this->submit($enrollment, 'POSTTEST', false);
        DB::table('questions')->update(['question_text' => 'ข้อความถูกแก้ภายหลัง']);
        $report = app(ReportingService::class)->report($admin, 'question', ['exam_type' => 'POSTTEST']);
        $this->assertCount(2, $report['rows']);
        foreach ($report['rows'] as $row) {
            $this->assertNotSame('ข้อความถูกแก้ภายหลัง', $row['question']);
            $this->assertSame(2, $row['answered']);
            $this->assertSame(1, $row['correct']);
            $this->assertSame(1, $row['incorrect']);
            $this->assertSame(50.0, $row['correct_percent']);
            $this->assertArrayNotHasKey('correct_answer', $row);
        }
    }

    public function test_admin_adjustment_requires_reason_is_idempotent_and_preserves_ledger(): void
    {
        [$admin, $learner] = $this->fixture();
        $url = '/admin/points/users/'.$learner->id.'/adjust';
        $body = ['points' => 10, 'reason' => 'เหตุผลทดสอบ', 'request_key' => (string) Str::uuid()];
        $this->actingAs($learner)->post($url, $body)->assertForbidden();
        $this->actingAs($admin)->post($url, [...$body, 'reason' => '     '])->assertSessionHasErrors('reason');
        $this->post($url, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, [...$body, 'points' => 20])->assertConflict();
        $this->assertSame(10, app(PointService::class)->balance($learner));
        $this->post($url, ['points' => -3, 'reason' => 'แก้ยอดด้วยรายการชดเชย', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame(7, app(PointService::class)->balance($learner));
        $this->assertSame(2, PointTransaction::count());
        $this->expectException(\LogicException::class);
        PointTransaction::firstOrFail()->update(['points' => 100]);
    }

    public function test_existing_completion_does_not_receive_retroactive_points(): void
    {
        [$admin, , $enrollment, $article] = $this->fixture();
        $session = $enrollment->session;
        $session->update(['points_enabled' => false, 'points_activated_at' => null, 'point_rules_snapshot' => null]);
        $this->learn($enrollment, $article);
        $this->submit($enrollment, 'POSTTEST', false);
        $this->travelTo($enrollment->fresh()->completed_at);
        $this->actingAs($admin)->put('/sessions/'.$session->id.'/points', ['points_enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($enrollment->fresh()->points_ineligible);
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $admin);
        DB::transaction(fn () => app(PointService::class)->award($request, $enrollment->fresh()));
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_private_queued_exports_all_formats_idempotency_and_revocation(): void
    {
        [$admin, $learner] = $this->fixture();
        Bus::fake([GenerateReportExport::class]);
        Storage::fake('reports');
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $body = ['type' => 'individual', 'format' => $format, 'request_key' => (string) Str::uuid()];
            $this->actingAs($admin)->post('/reports/exports', $body)->assertRedirect()->assertSessionHasNoErrors();
            $export = ReportExport::latest('id')->firstOrFail();
            $this->post('/reports/exports', $body)->assertRedirect();
            $this->assertSame($export->id, ReportExport::latest('id')->firstOrFail()->id);
            $this->post('/reports/exports', [...$body, 'type' => 'course'])->assertConflict();
            $job = new GenerateReportExport($export->id);
            $job->handle(app(ReportingService::class), app(ReportFileWriter::class), app(AuditService::class));
            $export->refresh();
            $this->assertSame('READY', $export->status);
            $this->assertSame(1, $export->row_count);
            $this->assertGreaterThan(100, $export->file_size);
            $this->get('/reports/exports/'.$export->token.'/download')->assertOk();
            $this->actingAs($learner)->withSession(['auth_version' => $learner->fresh()->session_version])->get('/reports/exports/'.$export->token.'/download')->assertNotFound();
            $this->actingAs($admin);
            $admin->increment('session_version');
            $admin->refresh();
            $this->withSession(['auth_version' => $admin->session_version]);
            $this->get('/reports/exports/'.$export->token.'/download')->assertForbidden();
        }
        $this->assertSame(3, ReportExport::count());
        $this->assertFalse(config('rewards.enabled'));
        $this->get('/rewards')->assertNotFound();
    }

    public function test_export_job_recovers_stale_lease_and_rejects_revoked_scope(): void
    {
        [$admin] = $this->fixture();
        Bus::fake([GenerateReportExport::class]);
        Storage::fake('reports');
        $this->actingAs($admin)->post('/reports/exports', ['type' => 'course', 'format' => 'csv', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $export = ReportExport::firstOrFail();
        $export->update(['status' => 'RUNNING', 'updated_at' => now()->subSeconds(241)]);
        (new GenerateReportExport($export->id))->handle(app(ReportingService::class), app(ReportFileWriter::class), app(AuditService::class));
        $this->assertSame('READY', $export->fresh()->status);
        $this->post('/reports/exports', ['type' => 'course', 'format' => 'csv', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $second = ReportExport::latest('id')->firstOrFail();
        $admin->roles()->detach();
        (new GenerateReportExport($second->id))->handle(app(ReportingService::class), app(ReportFileWriter::class), app(AuditService::class));
        $this->assertSame('FAILED', $second->fresh()->status);
        $this->assertNull($second->fresh()->storage_path);
    }

    public function test_xlsx_and_csv_keep_thai_codes_and_never_execute_text_formulas(): void
    {
        Storage::fake('reports');
        $disk = Storage::disk('reports');
        $writer = app(ReportFileWriter::class);
        $rows = collect([['employee_code' => '00123', 'name' => '=1+1', 'course' => 'ภาษาไทย', 'score' => 0], ['employee_code' => '00124', 'name' => "\t@SUM(1)", 'course' => 'อีกหลักสูตร', 'score' => null]]);
        $columns = ['employee_code' => 'รหัส', 'name' => 'ชื่อ', 'course' => 'หลักสูตร', 'score' => 'คะแนน'];
        $writer->write('xlsx', $disk->path('test.xlsx'), 'individual', $columns, $rows);
        $zip = new \ZipArchive;
        $zip->open($disk->path('test.xlsx'));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f', $xml);
        $zip->close();
        $reader = new Reader;
        $reader->open($disk->path('test.xlsx'));
        $read = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $read[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame('00123', $read[1][0]);
        $this->assertSame('=1+1', $read[1][1]);
        $this->assertSame('ภาษาไทย', $read[1][2]);
        $this->assertSame(0, $read[1][3]);
        $writer->write('csv', $disk->path('test.csv'), 'individual', $columns, $rows);
        $csv = $disk->get('test.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'00123,'=1+1,ภาษาไทย,0", $csv);
        $this->assertStringContainsString("'\t@SUM(1)", $csv);
    }

    public function test_export_write_failure_can_retry_without_partial_download(): void
    {
        [$admin] = $this->fixture();
        Bus::fake([GenerateReportExport::class]);
        Storage::fake('reports');
        $this->actingAs($admin)->post('/reports/exports', ['type' => 'course', 'format' => 'csv', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $export = ReportExport::firstOrFail();
        $writer = \Mockery::mock(ReportFileWriter::class);
        $writer->shouldReceive('write')->once()->andReturnUsing(function ($format, $path): void {
            file_put_contents($path, 'incomplete');
            throw new \RuntimeException('Injected disk write failure');
        });
        $job = new GenerateReportExport($export->id);
        try {
            $job->handle(app(ReportingService::class), $writer, app(AuditService::class));
            $this->fail('Expected write failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected disk write failure', $exception->getMessage());
        }
        $this->assertSame('QUEUED', $export->fresh()->status);
        $this->assertEmpty(Storage::disk('reports')->allFiles('exports'));
        $this->get('/reports/exports/'.$export->token.'/download')->assertForbidden();
        $job->handle(app(ReportingService::class), app(ReportFileWriter::class), app(AuditService::class));
        $this->assertSame('READY', $export->fresh()->status);
        $job->handle(app(ReportingService::class), app(ReportFileWriter::class), app(AuditService::class));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'reports.exported')->count());
        $this->travel(8)->days();
        $this->artisan('reports:purge')->assertSuccessful();
        $this->assertSame('EXPIRED', $export->fresh()->status);
        $this->assertEmpty(Storage::disk('reports')->allFiles('exports'));
    }
}
