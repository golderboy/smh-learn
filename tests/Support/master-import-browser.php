<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\MasterImport;
use App\Models\User;
use App\Services\MasterImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Master import browser fixture failed: '.get_class($exception)."\n");
    exit(1);
});
if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
    throw new RuntimeException('Exact local database required.');
}
$mode = $argv[1] ?? '';
$key = $argv[2] ?? '';
if (! preg_match('/\AMI-[a-f0-9]{12}\z/', $key)) {
    throw new RuntimeException('Invalid fixture identifier.');
}
$folder = base_path('.cache/master-import-browser/'.$key);
$actor = User::where('username', '0000000000001')->where('is_demo', true)->firstOrFail();
if ($mode === 'prepare') {
    if (! is_dir($folder)) {
        mkdir($folder, 0770, true);
    }
    $username = '9900'.random_int(10000000000, 99999999999);
    if (User::where('username', $username)->exists()) {
        throw new RuntimeException('Fixture identifier conflict.');
    }
    $data = [
        'departments' => [[$key.'-D', 'แผนกนำเข้า']],
        'positions' => [[$key.'-P', 'ตำแหน่งนำเข้า']],
        'categories' => [[$key.'-C', 'หมวดนำเข้า', 'รายละเอียดหมวด']],
        'stores' => [[$key.'-S', 'ร้านสวัสดิการนำเข้า', 'ช่องทางติดต่อ']],
        'users' => [[$username, $key.'-U', 'ผู้ใช้นำเข้า', 'องค์กร', $key.'-D', $key.'-P', null, '0800000000', null], [$username, $key.'-U', 'ผู้ใช้นำเข้า', 'องค์กร', $key.'-D', $key.'-P', null, '0800000000', null]],
    ];
    foreach ($data as $kind => $rows) {
        $writer = new Writer;
        $writer->openToFile($folder.'/'.$kind.'.xlsx');
        $writer->getCurrentSheet()->setName('Data');
        foreach ([MasterImportService::DEFINITIONS[$kind]['headers'], ...$rows] as $values) {
            $writer->addRow(new Row(array_map(fn ($value) => Cell::fromValue($value), $values)));
        }
        $writer->close();
    }
    $category = CourseCategory::create(['code' => $key.'-EXAM', 'name' => 'หมวดการสอบ', 'created_by' => $actor->id]);
    $course = Course::create(['code' => $key, 'title' => 'หลักสูตรจัดการข้อสอบ', 'category_id' => $category->id, 'created_by' => $actor->id]);
    $version = $course->versions()->create(['version_number' => 1, 'version_name' => 'ฉบับที่ 1']);
    $manifest = ['username' => $username, 'version_id' => $version->id, 'course_id' => $course->id];
    file_put_contents($folder.'/fixture.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    echo json_encode($manifest, JSON_THROW_ON_ERROR);
} elseif ($mode === 'check') {
    $manifest = json_decode(file_get_contents($folder.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $user = User::where('employee_code', $key.'-U')->firstOrFail();
    $ok = $user->username === $manifest['username'] && $user->roles()->pluck('code')->all() === ['learner'] && Hash::check('learn123456', $user->password_hash) && $user->must_change_password && ! $user->is_demo;
    $store = DB::table('welfare_stores')->where('code', $key.'-S')->first();
    $ok = $ok && $store?->status === 'PENDING' && DB::table('welfare_store_users')->where('welfare_store_id', $store?->id)->count() === 0;
    if (! $ok) {
        throw new RuntimeException('Imported user/store invariant failed.');
    }
    $root = User::where('root_admin_key', 'PRIMARY')->firstOrFail();
    echo json_encode(['ok' => true, 'users' => User::where('employee_code', $key.'-U')->count(), 'root_needs_first_sign_in' => $root->must_change_password && Hash::check('P@ssw0rd112233', $root->password_hash), 'store_status' => $store->status, 'active_questions' => DB::table('questions')->where('course_id', $manifest['course_id'])->where('is_active', true)->count(), 'archived_questions' => DB::table('questions')->where('course_id', $manifest['course_id'])->where('is_active', false)->count()], JSON_THROW_ON_ERROR);
} elseif ($mode === 'cleanup') {
    DB::transaction(function () use ($actor, $key, $folder): void {
        DB::table('system_settings')->where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
        $course = Course::where('code', $key)->where('created_by', $actor->id)->first();
        if ($course !== null) {
            $versionIds = $course->versions()->pluck('id');
            if (DB::table('course_sessions')->whereIn('course_version_id', $versionIds)->exists()) {
                throw new RuntimeException('Learning history exists; fixture cleanup refused.');
            }
            $examIds = DB::table('exams')->whereIn('course_version_id', $versionIds)->pluck('id');
            $questionIds = DB::table('questions')->where('course_id', $course->id)->pluck('id');
            DB::table('exam_questions')->whereIn('exam_id', $examIds)->delete();
            DB::table('question_choices')->whereIn('question_id', $questionIds)->delete();
            DB::table('questions')->whereIn('id', $questionIds)->delete();
            DB::table('question_imports')->whereIn('course_version_id', $versionIds)->delete();
            DB::table('exams')->whereIn('id', $examIds)->delete();
            DB::table('question_categories')->where('course_id', $course->id)->delete();
            DB::table('course_versions')->whereIn('id', $versionIds)->delete();
            $course->delete();
        }
        $users = User::where('employee_code', $key.'-U')->get();
        foreach ($users as $user) {
            if ($user->isRootAdministrator() || $user->hasRole('administrator') || DB::table('course_enrollments')->where('user_id', $user->id)->exists()) {
                throw new RuntimeException('Protected identity/history exists.');
            }
            $user->roles()->detach();
            $user->delete();
        }
        DB::table('welfare_stores')->where('code', $key.'-S')->where('created_by', $actor->id)->where('status', 'PENDING')->delete();
        DB::table('course_categories')->whereIn('code', [$key.'-C', $key.'-EXAM'])->where('created_by', $actor->id)->delete();
        DB::table('departments')->where('code', $key.'-D')->delete();
        DB::table('positions')->where('code', $key.'-P')->delete();
        foreach (['departments', 'positions', 'categories', 'stores', 'users'] as $kind) {
            if (is_file($folder.'/'.$kind.'.xlsx')) {
                MasterImport::where('created_by', $actor->id)->where('sha256', hash_file('sha256', $folder.'/'.$kind.'.xlsx'))->delete();
            }
        }
    });
    echo '{"cleanup":true}';
} else {
    throw new RuntimeException('Unknown fixture action.');
}
