<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareReleaseText extends Command
{
    protected $signature = 'app:prepare-release-text {--apply : Apply exact presentation text replacements in the local database}';

    protected $description = 'Remove known development wording while preserving identities, media, history and financial records.';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('Restricted to the exact local database.');

            return self::FAILURE;
        }
        $plan = [];
        $add = function (string $table, string $column, string $old, string $new, array $scope = []) use (&$plan): void {
            $query = DB::table($table)->where($column, $old);
            foreach ($scope as $key => $value) {
                $query->where($key, $value);
            }
            $count = (clone $query)->count();
            if ($count > 0) {
                $plan[] = compact('table', 'column', 'old', 'new', 'scope', 'count');
            }
        };
        foreach ([['departments', 'DEMO-A', 'แผนกทดสอบ ก', 'แผนก ก'], ['departments', 'DEMO-B', 'แผนกทดสอบ ข', 'แผนก ข'], ['positions', 'DEMO-STAFF', 'บุคลากรทดสอบ', 'บุคลากร'], ['positions', 'DEMO-HEAD', 'หัวหน้าแผนกทดสอบ', 'หัวหน้าแผนก']] as [$table, $code, $old, $new]) {
            $add($table, 'name', $old, $new, ['code' => $code]);
        }
        foreach (['DEMO-IT' => 'เทคโนโลยีสารสนเทศ', 'DEMO-SAFETY' => 'ความปลอดภัย', 'DEMO-QUALITY' => 'คุณภาพองค์กร'] as $code => $name) {
            $add('course_categories', 'name', $name.' (ทดสอบ)', $name, ['code' => $code]);
            $add('course_categories', 'description', 'หมวดจำลองสำหรับตรวจสิทธิ์ผู้คุม', 'หมวดหลักสูตรสำหรับพัฒนาบุคลากร', ['code' => $code]);
        }
        for ($number = 1; $number <= 10; $number++) {
            $scope = ['is_demo' => true, 'employee_code' => sprintf('DEMO%03d', $number)];
            $add('users', 'first_name', sprintf('ผู้ใช้จำลอง%02d', $number), sprintf('ผู้ใช้งาน%02d', $number), $scope);
            $add('users', 'last_name', 'ทดสอบ', 'ระบบ', $scope);
            $add('users', 'display_name', sprintf('ผู้ใช้จำลอง%02d ทดสอบ', $number), sprintf('ผู้ใช้งาน%02d ระบบ', $number), $scope);
            $add('users', 'employment_status', 'ข้อมูลจำลอง', 'บุคลากร', $scope);
        }
        $add('courses', 'description', 'หลักสูตรทดสอบนำเข้าจากเอกสารและวิดีโอที่หน่วยงานจัดเตรียม', 'หลักสูตรจากเอกสารและวิดีโอที่หน่วยงานจัดเตรียม', ['code' => 'RESEARCH-METHODS']);
        $courseId = DB::table('courses')->where('code', 'RESEARCH-METHODS')->value('id');
        if ($courseId !== null) {
            $versionId = DB::table('course_versions')->where('course_id', $courseId)->where('version_number', 1)->value('id');
            $add('course_versions', 'version_name', 'ฉบับทดสอบ DEV 1', 'ฉบับที่ 1', ['course_id' => $courseId]);
            if ($versionId !== null) {
                $add('course_sessions', 'name', 'รอบทดสอบ DEV', 'รอบเรียนที่ 1', ['course_version_id' => $versionId]);
            }
        }
        $organization = SystemSetting::where('key', 'organization_name')->first();
        $resetOrganization = ($organization?->value['value'] ?? null) === 'องค์กรทดสอบ SMH';
        $this->line(json_encode(['apply' => (bool) $this->option('apply'), 'text_fields' => array_sum(array_column($plan, 'count')) + (int) $resetOrganization, 'tables' => array_values(array_unique(array_column($plan, 'table'))), 'records_deleted' => 0], JSON_THROW_ON_ERROR));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($plan, $resetOrganization, $organization): void {
            SystemSetting::where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
            foreach ($plan as $item) {
                $query = DB::table($item['table'])->where($item['column'], $item['old']);
                foreach ($item['scope'] as $key => $value) {
                    $query->where($key, $value);
                }
                $query->update([$item['column'] => $item['new'], 'updated_at' => now()]);
            }
            if ($resetOrganization) {
                $organization->update(['value' => ['value' => 'องค์กรของฉัน']]);
            }
            DB::table('audit_logs')->insert(['action' => 'system.presentation_prepared', 'entity_type' => 'system', 'new_values' => json_encode(['text_fields' => array_sum(array_column($plan, 'count')), 'records_deleted' => 0], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });

        return self::SUCCESS;
    }
}
