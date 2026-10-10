<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

class ReportingCapacityTest extends FoundationTestCase
{
    public function test_one_thousand_synthetic_learners_have_bounded_queries_paginated_reports_and_scoped_options(): void
    {
        $admin = $this->userWithRole('administrator');
        $category = CourseCategory::create(['code' => 'CAPACITY', 'name' => 'หมวดจำลองทดสอบโหลด']);
        $course = Course::create(['category_id' => $category->id, 'code' => 'CAPACITY', 'title' => 'หลักสูตรจำลอง 1000 คน', 'status' => 'published', 'created_by' => $admin->id]);
        $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ทดสอบ', 'published_at' => now()]);
        $session = $version->sessions()->create(['name' => 'รอบจำลอง', 'fiscal_year' => 2570, 'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => 'open']);
        $password = Hash::make('SyntheticOnly123!');
        $users = [];
        for ($i = 1; $i <= 1000; $i++) {
            $users[] = ['employee_code' => 'CAPACITY-'.$i, 'username' => '999'.str_pad((string) $i, 12, '0', STR_PAD_LEFT),
                'password_hash' => $password, 'first_name' => 'ผู้เรียนจำลอง', 'last_name' => (string) $i,
                'display_name' => 'ผู้เรียนจำลอง '.$i, 'is_active' => true, 'must_change_password' => false,
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('users')->insert($users);
        $enrollments = User::where('employee_code', 'like', 'CAPACITY-%')->pluck('id')->map(fn ($id): array => [
            'user_id' => $id, 'course_session_id' => $session->id, 'state' => 'ENROLLED', 'enrolled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ])->all();
        DB::table('course_enrollments')->insert($enrollments);
        DB::enableQueryLog();
        $start = microtime(true);
        $reports = app(ReportingService::class);
        $result = $reports->report($admin, 'individual');
        $reportTime = round((microtime(true) - $start) * 1000, 2);
        $queryCount = count(DB::getQueryLog());
        $this->assertCount(1000, $result['rows']);
        $this->assertSame(1000, $result['summary']['total']);
        // This catches relation queries growing per learner; time is evidence, not a machine-specific assertion.
        $this->assertLessThan(30, $queryCount);
        DB::flushQueryLog();
        $options = $reports->referenceOptions($admin);
        $this->assertCount(1000, $options['people']);
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($query): bool => str_contains($query['query'], 'exam_attempts') || str_contains($query['query'], 'point_transactions')));
        $learner = User::where('employee_code', 'CAPACITY-1')->firstOrFail();
        $this->assertCount(1, $reports->referenceOptions($learner)['people']);
        $this->actingAs($admin)->get('/reports')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.rows.total', 1000)->has('report.rows.data', 25)->where('report.summary.total', 1000));
        $this->assertSame(1000, CourseEnrollment::count());
        fwrite(STDOUT, json_encode(['capacity_fixture' => 1000, 'report_ms' => $reportTime, 'report_queries' => $queryCount,
            'php_peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n");
        DB::disableQueryLog();
    }
}
