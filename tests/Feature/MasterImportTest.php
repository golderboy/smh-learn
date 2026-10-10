<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\MasterImport;
use App\Models\User;
use App\Models\WelfareStore;
use App\Services\MasterImportService;
use Database\Seeders\RootAdministratorSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

class MasterImportTest extends FoundationTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /** @param list<array<int, mixed>> $rows */
    private function file(string $kind, array $rows, string $sheet = 'Data'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'master-');
        $this->temporary[] = $path;
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($sheet);
        foreach ([MasterImportService::DEFINITIONS[$kind]['headers'], ...$rows] as $values) {
            $writer->addRow(new Row(array_map(fn ($value) => Cell::fromValue($value), $values)));
        }
        $writer->close();

        return new UploadedFile($path, 'master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true);
    }

    private function confirm(string $kind): MasterImport
    {
        $import = MasterImport::where('kind', $kind)->latest('id')->firstOrFail();
        $this->post('/admin/imports/'.$kind.'/commit', ['token' => $import->token])->assertRedirect()->assertSessionHasNoErrors();

        return $import;
    }

    public function test_five_downloads_have_exact_empty_data_headers_and_instructions(): void
    {
        $admin = $this->userWithRole('administrator');
        $this->actingAs($admin);
        foreach (MasterImportService::DEFINITIONS as $kind => $definition) {
            $this->get('/admin/imports?kind='.$kind)->assertOk();
            $this->get('/admin/imports/'.$kind.'/template')->assertOk()->assertDownload('learning-'.$kind.'-template.xlsx');
            $reader = new Reader;
            $reader->open(base_path('docs/templates/master-'.$kind.'.xlsx'));
            $found = false;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== 'Data') {
                    continue;
                }
                $found = true;
                foreach ($sheet->getRowIterator() as $line => $row) {
                    if ($line === 1) {
                        $this->assertSame($definition['headers'], $row->toArray());
                    } else {
                        $this->assertTrue($row->isEmpty());
                    }
                }
            }
            $reader->close();
            $this->assertTrue($found);
        }
    }

    public function test_imports_all_foundation_types_and_stores_remain_pending_without_operators(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        foreach ([
            'departments' => [['DEPT001', 'แผนกบุคลากร']],
            'positions' => [['POS001', 'เจ้าหน้าที่']],
            'categories' => [['CAT001', 'พัฒนาความรู้', 'หมวดหลักสูตร']],
            'stores' => [['STORE001', 'ร้านสวัสดิการ', 'ช่องทางติดต่อ']],
        ] as $kind => $rows) {
            $this->post('/admin/imports/'.$kind.'/preview', ['file' => $this->file($kind, $rows)])->assertRedirect()->assertSessionHasNoErrors();
            $this->confirm($kind);
        }
        $this->assertDatabaseHas('departments', ['code' => 'DEPT001', 'is_active' => true]);
        $this->assertDatabaseHas('positions', ['code' => 'POS001', 'is_active' => true]);
        $this->assertDatabaseHas('course_categories', ['code' => 'CAT001', 'is_active' => true]);
        $store = WelfareStore::where('code', 'STORE001')->firstOrFail();
        $this->assertSame('PENDING', $store->status);
        $this->assertNull($store->approved_at);
        $this->assertSame(0, $store->operators()->count());
        $this->put('/admin/welfare/stores/'.$store->id.'/status', ['status' => 'APPROVED', 'reason' => 'อนุมัติร้าน'])->assertSessionHasErrors('status');
    }

    public function test_users_preserve_identifiers_skip_existing_and_repeat_rows_and_never_overwrite_privileges(): void
    {
        $admin = $this->userWithRole('administrator');
        $oldPassword = $admin->password_hash;
        $before = User::count();
        Department::create(['code' => 'DEP', 'name' => 'แผนก']);
        $rows = [
            [$admin->username, $admin->employee_code, 'เปลี่ยน', 'สิทธิ์', null, null, null, null, null],
            ['0000000000015', null, 'ชื่อใหม่', 'นามสกุล', 'DEP', null, 'new@example.org', '0800000000', null],
            ['0000000000015', 'DIFFERENT', 'ซ้ำ', 'ข้าม', null, null, null, null, null],
        ];
        $this->actingAs($admin)->post('/admin/imports/users/preview', ['file' => $this->file('users', $rows)])->assertRedirect()->assertSessionHasNoErrors();
        $import = MasterImport::firstOrFail();
        $this->assertSame([true, false, true], array_column($import->rows, 'skip'));
        $this->assertSame($before, User::count());
        $this->assertStringNotContainsString('0000000000015', DB::table('master_imports')->value('rows'));
        $this->confirm('users');
        $this->post('/admin/imports/users/commit', ['token' => $import->token])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 1, User::count());
        $user = User::where('username', '0000000000015')->firstOrFail();
        $this->assertSame('0000000000015', $user->employee_code);
        $this->assertSame('0800000000', $user->phone);
        $this->assertTrue(Hash::check('learn123456', $user->password_hash));
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->is_demo);
        $this->assertFalse($user->isRootAdministrator());
        $this->assertSame(['learner'], $user->roles()->pluck('code')->all());
        $this->assertSame($oldPassword, $admin->fresh()->password_hash);
        $this->assertTrue($admin->fresh()->hasRole('administrator'));
        $this->assertSame([], $import->fresh()->rows);
        $this->assertSame(['kind' => 'users', 'count' => 1, 'skipped' => 2, 'sha256' => $import->sha256], AuditLog::where('action', 'master_data.imported')->firstOrFail()->new_values);
    }

    public function test_invalid_rows_duplicates_and_numeric_identifiers_reject_without_partial_writes(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        foreach ([
            [['OK', 'แผนกถูกต้อง'], ['OK', 'รหัสซ้ำ']],
            [['OK', 'แผนกถูกต้อง'], [123, 'รหัสตัวเลข Excel']],
            [['OK', 'แผนกถูกต้อง'], ['BAD', null]],
        ] as $rows) {
            $this->post('/admin/imports/departments/preview', ['file' => $this->file('departments', $rows)])->assertSessionHasErrors('file');
            $this->assertDatabaseMissing('departments', ['code' => 'OK']);
        }
        foreach ([1234567890123, '1234567890123456', 'admins'] as $username) {
            $this->post('/admin/imports/users/preview', ['file' => $this->file('users', [[$username, 'NEW', 'ชื่อ', 'นามสกุล', null, null, null, null, null]])])->assertSessionHasErrors('file');
        }
        $this->post('/admin/imports/users/preview', ['file' => $this->file('users', [['00015', null, 'ชื่อ', 'นามสกุล', 'MISSING', null, null, null, null]])])->assertSessionHasErrors('file');
        $this->post('/admin/imports/positions/preview', ['file' => $this->file('positions', [['P1', 'ตำแหน่ง']], 'Wrong')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('master_imports', 0);
    }

    public function test_one_thousand_users_import_with_native_password_cost_and_leading_zeros(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        config(['hashing.bcrypt.rounds' => 12]);
        Hash::forgetDrivers();
        $rows = [];
        for ($index = 0; $index < 1000; $index++) {
            $rows[] = [str_pad((string) ($index + 15000), 15, '0', STR_PAD_LEFT), null, 'บุคลากร', (string) $index, null, null, null, null, null];
        }
        $started = microtime(true);
        $this->post('/admin/imports/users/preview', ['file' => $this->file('users', $rows)])->assertSessionHasNoErrors();
        $previewMs = round((microtime(true) - $started) * 1000, 2);
        $started = microtime(true);
        $this->confirm('users');
        $commitMs = round((microtime(true) - $started) * 1000, 2);
        $this->assertDatabaseCount('users', 1001);
        $first = User::where('username', '000000000015000')->firstOrFail();
        $last = User::where('username', '000000000015999')->firstOrFail();
        $this->assertTrue(Hash::check('learn123456', $first->password_hash));
        $this->assertSame(12, password_get_info($first->password_hash)['options']['cost']);
        $this->assertSame(1000, User::where('must_change_password', true)->where('is_demo', false)->whereHas('roles', fn ($query) => $query->where('code', 'learner'))->count());
        $this->assertTrue($last->must_change_password);
        $this->assertSame(['learner'], $last->roles()->pluck('code')->all());
        $this->assertFalse($last->isRootAdministrator());
        fwrite(STDOUT, json_encode(['master_import_users' => 1000, 'bcrypt_cost' => 12, 'preview_ms' => $previewMs, 'commit_ms' => $commitMs], JSON_THROW_ON_ERROR)."\n");
        $this->assertLessThan(25000, $commitMs);
    }

    public function test_commit_rechecks_references_and_skips_users_created_after_preview(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $department = Department::create(['code' => 'DEP', 'name' => 'แผนก']);
        $this->post('/admin/imports/users/preview', ['file' => $this->file('users', [['00015', null, 'ชื่อ', 'นามสกุล', 'DEP', null, null, null, null]])])->assertSessionHasNoErrors();
        $import = MasterImport::firstOrFail();
        $department->update(['is_active' => false]);
        $this->post('/admin/imports/users/commit', ['token' => $import->token])->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('users', ['username' => '00015']);
        $this->assertNull($import->fresh()->committed_at);
        $existing = User::factory()->create(['username' => '00015', 'employee_code' => 'LATER']);
        $password = $existing->password_hash;
        $this->confirm('users');
        $this->assertSame($password, $existing->fresh()->password_hash);
        $this->assertSame('LATER', $existing->fresh()->employee_code);
    }

    public function test_preview_is_owner_bound_expiring_and_admin_only(): void
    {
        $admin = $this->userWithRole('administrator');
        $other = $this->userWithRole('administrator');
        $this->actingAs($admin)->post('/admin/imports/departments/preview', ['file' => $this->file('departments', [['ONE', 'แผนก']])])->assertSessionHasNoErrors();
        $import = MasterImport::firstOrFail();
        $this->actingAs($other)->post('/admin/imports/departments/commit', ['token' => $import->token])->assertNotFound();
        $this->actingAs($admin)->post('/admin/imports/positions/commit', ['token' => $import->token])->assertNotFound();
        $import->update(['expires_at' => now()->subMinute()]);
        $this->post('/admin/imports/departments/commit', ['token' => $import->token])->assertSessionHasErrors('file');
        foreach (['learner', 'instructor', 'welfare-shop'] as $role) {
            $this->actingAs($this->userWithRole($role))->get('/admin/imports')->assertForbidden();
            $this->get('/admin/imports/users/template')->assertForbidden();
            $this->post('/admin/imports/departments/commit', ['token' => $import->token])->assertForbidden();
        }
        $this->assertDatabaseMissing('departments', ['code' => 'ONE']);
    }

    public function test_root_is_seeded_once_protected_and_can_change_own_password(): void
    {
        $this->seed(RootAdministratorSeeder::class);
        $root = User::where('username', 'admins')->firstOrFail();
        $this->assertTrue($root->isRootAdministrator());
        $this->assertTrue($root->hasPermission('master.import'));
        $this->assertTrue(Hash::check('P@ssw0rd112233', $root->password_hash));
        $admin = $this->userWithRole('administrator');
        $this->userWithRole('administrator');
        $this->actingAs($admin)->get('/admin/users/'.$root->id.'/edit')->assertForbidden();
        $this->put('/admin/users/'.$root->id, $this->userData($root))->assertForbidden();
        $this->post('/admin/users/'.$root->id.'/reset-password', ['reason' => 'พยายามรีเซ็ตบัญชีตั้งต้น'])->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['username' => 'admins', 'password' => 'P@ssw0rd112233'])->assertRedirect('/password/change');
        $this->assertAuthenticatedAs($root);
        $this->put('/password/change', ['current_password' => 'P@ssw0rd112233', 'password' => 'RootNewPassword123!', 'password_confirmation' => 'RootNewPassword123!'])->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->seed(RootAdministratorSeeder::class);
        $this->assertTrue(Hash::check('RootNewPassword123!', $root->fresh()->password_hash));
        $this->assertFalse($root->fresh()->must_change_password);
        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->component('Profile')->where('profile.is_root_admin', true));
        $this->put('/profile', ['email' => 'changed@example.org', 'phone' => '0800000000'])->assertForbidden();
        $this->assertNull($root->fresh()->email);
        $this->assertNull($root->fresh()->phone);
        $this->assertSame(1, User::whereNotNull('root_admin_key')->count());
        $this->assertSame(3, User::whereHas('roles', fn ($query) => $query->where('code', 'administrator'))->count());
        $this->assertSame(0, DB::table('users')->where('username', 'admins')->where('is_demo', true)->count());
    }
}
