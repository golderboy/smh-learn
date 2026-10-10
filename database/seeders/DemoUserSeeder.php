<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CourseCategory;
use App\Models\Department;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $allowed = app()->environment('local') ? 'smh_learning_dev' : (app()->environment('testing') ? 'smh_learning_test' : null);
        if ($allowed === null || DB::connection()->getDatabaseName() !== $allowed) {
            throw new LogicException('Demo seeding is restricted to this project local/test database.');
        }
        $this->call(FoundationSeeder::class);
        DB::transaction(function (): void {
            $departmentOne = Department::firstOrCreate(['code' => 'DEMO-A'], ['name' => 'แผนก ก']);
            $departmentTwo = Department::firstOrCreate(['code' => 'DEMO-B'], ['name' => 'แผนก ข']);
            $staffPosition = Position::firstOrCreate(['code' => 'DEMO-STAFF'], ['name' => 'บุคลากร']);
            $headPosition = Position::firstOrCreate(['code' => 'DEMO-HEAD'], ['name' => 'หัวหน้าแผนก']);
            $users = [];
            for ($number = 1; $number <= 10; $number++) {
                $username = str_pad((string) $number, 13, '0', STR_PAD_LEFT);
                $user = User::firstOrCreate(['username' => $username], [
                    'employee_code' => sprintf('DEMO%03d', $number),
                    'password_hash' => (string) config('identity.initial_password'),
                    'first_name' => sprintf('ผู้ใช้งาน%02d', $number),
                    'last_name' => 'ระบบ',
                    'display_name' => sprintf('ผู้ใช้งาน%02d ระบบ', $number),
                    'department_id' => $number <= 6 ? $departmentOne->id : $departmentTwo->id,
                    'position_id' => $number === 4 ? $headPosition->id : $staffPosition->id,
                    'employment_status' => 'บุคลากร', 'is_active' => true, 'is_demo' => true,
                    'must_change_password' => (bool) config('identity.require_initial_password_change'),
                ]);
                if (! $user->is_demo) {
                    throw new LogicException('A demo username conflicts with a non-demo account; no changes applied.');
                }
                if ($user->wasRecentlyCreated) {
                    $codes = ['learner'];
                    if ($number === 1) {
                        $codes[] = 'administrator';
                    } elseif (in_array($number, [2, 3], true)) {
                        $codes[] = 'instructor';
                    } elseif ($number === 4) {
                        $codes[] = 'department-manager';
                    }
                    $user->roles()->sync(Role::query()->whereIn('code', $codes)->pluck('id')->all());
                }
                $users[$number] = $user;
            }
            foreach ([['DEMO-IT', 'เทคโนโลยีสารสนเทศ', [2]], ['DEMO-SAFETY', 'ความปลอดภัย', [3]], ['DEMO-QUALITY', 'คุณภาพองค์กร', [2, 3]]] as [$code, $name, $instructorNumbers]) {
                $category = CourseCategory::firstOrCreate(['code' => $code], ['name' => $name, 'description' => 'หมวดหลักสูตรสำหรับพัฒนาบุคลากร', 'created_by' => $users[1]->id]);
                if ($category->wasRecentlyCreated) {
                    $assignments = [];
                    foreach ($instructorNumbers as $number) {
                        $assignments[$users[$number]->id] = ['assigned_by' => $users[1]->id];
                    }
                    $category->instructors()->sync($assignments);
                }
            }
        });
    }
}
