<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CourseCategory;
use App\Models\Department;
use App\Models\ExternalIdentity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RootAdministratorSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;

class FoundationManagementTest extends FoundationTestCase
{
    public function test_demo_collision_does_not_overwrite_a_non_demo_account(): void
    {
        $original = User::factory()->create(['username' => '0000000000001', 'is_demo' => false, 'password_hash' => 'OriginalPassword123!']);
        try {
            $this->seed(DemoUserSeeder::class);
            $this->fail('A non-demo identity must stop demo seeding.');
        } catch (LogicException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('departments', 0);
            $this->assertDatabaseCount('positions', 0);
            $this->assertDatabaseCount('course_categories', 0);
            $this->assertTrue(Hash::check('OriginalPassword123!', $original->fresh()->password_hash));
            $this->assertFalse($original->fresh()->is_demo);
        }
    }

    public function test_local_demo_commands_refuse_a_non_dev_environment(): void
    {
        $this->artisan('dev:initialize-foundation')->assertExitCode(1);
        $this->artisan('dev:seed-demo', ['--reset-passwords' => true])->assertExitCode(1);
        $this->artisan('dev:prepare-storage')->assertExitCode(1);
        $this->artisan('dev:learning-status')->assertExitCode(1);
        $this->artisan('dev:prepare-research-assessment')->assertExitCode(1);
        $this->artisan('dev:import-research-course', ['--publish' => true])->assertExitCode(1);
        $this->artisan('dev:reset-learning-qa', ['code' => 'SMH-E2E-desktop-chromium'])->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_seed_is_repeatable_and_preserves_changed_passwords(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->assertDatabaseCount('users', 10);
        $user = User::query()->where('username', '0000000000001')->firstOrFail();
        $user->update(['password_hash' => 'ChangedPassword123!', 'must_change_password' => false]);
        $this->seed(DemoUserSeeder::class);
        $this->assertDatabaseCount('users', 10);
        $this->assertTrue(Hash::check('ChangedPassword123!', $user->fresh()->password_hash));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertDatabaseCount('course_categories', 3);
        $this->assertDatabaseCount('course_category_instructors', 4);
    }

    public function test_learners_are_denied_all_admin_actions(): void
    {
        $user = $this->userWithRole('learner');
        $this->actingAs($user);
        foreach (['/admin/users', '/admin/users/create', '/admin/organization/departments', '/admin/roles', '/admin/settings', '/admin/audit', '/categories'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
        $this->post('/admin/users', $this->userData())->assertForbidden();
        $this->put('/admin/settings', ['organization_name' => 'ไม่อนุญาต', 'session_timeout_minutes' => 15])->assertForbidden();
    }

    public function test_admin_can_create_edit_disable_and_reset_user(): void
    {
        $admin = $this->userWithRole('administrator');
        $payload = $this->userData();
        $this->actingAs($admin)->post('/admin/users', $payload)->assertRedirect('/admin/users');
        $user = User::query()->where('username', $payload['username'])->firstOrFail();
        $this->assertTrue(Hash::check('smh123456', $user->password_hash));
        $this->assertTrue($user->must_change_password);
        $payload['is_active'] = false;
        $this->put('/admin/users/'.$user->id, $payload)->assertRedirect('/admin/users');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $user->update(['password_hash' => 'ChangedPassword123!']);
        $this->post('/admin/users/'.$user->id.'/reset-password', ['reason' => 'จำลองการลืมรหัสผ่าน'])->assertRedirect();
        $this->assertTrue(Hash::check('smh123456', $user->fresh()->password_hash));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'reason' => 'จำลองการลืมรหัสผ่าน']);
    }

    public function test_numeric_username_accepts_fifteen_digits_but_rejects_duplicates(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $payload = $this->userData();
        $payload['username'] = '000000000000015';
        $this->post('/admin/users', $payload)->assertRedirect('/admin/users');
        $payload['employee_code'] = 'TEST-SECOND';
        $this->post('/admin/users', $payload)->assertSessionHasErrors('username');
        $payload['username'] = '1234567890123456';
        $this->post('/admin/users', $payload)->assertSessionHasErrors('username');
    }

    public function test_last_active_administrator_cannot_be_disabled_or_demoted(): void
    {
        $admin = $this->userWithRole('administrator');
        $payload = $this->userData($admin);
        $payload['role_ids'] = [Role::query()->where('code', 'administrator')->firstOrFail()->id];
        $payload['is_active'] = false;
        $this->actingAs($admin)->put('/admin/users/'.$admin->id, $payload)->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
        $payload['is_active'] = true;
        $payload['role_ids'] = [Role::query()->where('code', 'learner')->firstOrFail()->id];
        $this->put('/admin/users/'.$admin->id, $payload)->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->hasRole('administrator'));
    }

    public function test_password_reset_requires_reason_and_revokes_existing_session(): void
    {
        $admin = $this->userWithRole('administrator');
        $user = $this->userWithRole('learner');
        $this->actingAs($admin)->post('/admin/users/'.$user->id.'/reset-password', [])->assertSessionHasErrors('reason');
        $this->post('/admin/users/'.$user->id.'/reset-password', ['reason' => 'ทดสอบรีเซ็ต'])->assertRedirect();
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1])->get('/dashboard')->assertRedirect('/login');
    }

    public function test_instructor_can_view_only_admin_assigned_categories_and_cannot_assign_self(): void
    {
        $admin = $this->userWithRole('administrator');
        $instructor = $this->userWithRole('instructor');
        $owned = CourseCategory::create(['code' => 'OWNED', 'name' => 'หมวดที่ดูแล']);
        $other = CourseCategory::create(['code' => 'OTHER', 'name' => 'หมวดอื่น']);
        $owned->instructors()->attach($instructor->id, ['assigned_by' => $admin->id]);
        $this->actingAs($instructor)->get('/categories')->assertInertia(fn (Assert $page) => $page->where('categories.total', 1)->where('categories.data.0.id', $owned->id));
        $this->get('/categories/'.$owned->id)->assertOk();
        $this->get('/categories/'.$other->id)->assertForbidden();
        $this->get('/categories/'.$owned->id.'/edit')->assertForbidden();
        $this->put('/categories/'.$other->id, ['instructor_ids' => [$instructor->id]])->assertForbidden();
        $this->assertFalse($instructor->supervises($other));
    }

    public function test_admin_can_assign_multiple_categories_and_revoke_scope_immediately(): void
    {
        $admin = $this->userWithRole('administrator');
        $learner = $this->userWithRole('learner');
        $this->actingAs($admin)->post('/categories', ['code' => 'ASSIGN', 'name' => 'หมวดมอบหมาย', 'description' => null, 'is_active' => true, 'instructor_ids' => [$learner->id]])->assertRedirect('/categories');
        $category = CourseCategory::query()->where('code', 'ASSIGN')->firstOrFail();
        $this->assertTrue($learner->hasRole('instructor'));
        $this->assertTrue($learner->supervises($category));
        $this->put('/categories/'.$category->id, ['code' => 'ASSIGN', 'name' => 'หมวดมอบหมาย', 'is_active' => true, 'instructor_ids' => []])->assertRedirect('/categories');
        $this->assertFalse($learner->supervises($category));
        $this->actingAs($learner)->get('/categories/'.$category->id)->assertForbidden();
    }

    public function test_category_assignment_rejects_inactive_users_and_duplicate_entries(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $inactive = User::factory()->create(['is_active' => false]);
        $this->post('/categories', ['code' => 'BAD', 'name' => 'ไม่ผ่าน', 'is_active' => true, 'instructor_ids' => [$inactive->id]])->assertSessionHasErrors('instructor_ids.0');
        $active = User::factory()->create();
        $this->post('/categories', ['code' => 'BAD', 'name' => 'ไม่ผ่าน', 'is_active' => true, 'instructor_ids' => [$active->id, $active->id]])->assertSessionHasErrors('instructor_ids.0');
        $this->assertDatabaseMissing('course_categories', ['code' => 'BAD']);
    }

    public function test_department_manager_sees_only_own_department_without_citizen_identifiers(): void
    {
        $own = Department::create(['code' => 'OWN', 'name' => 'แผนกตนเอง']);
        $other = Department::create(['code' => 'OTHER', 'name' => 'แผนกอื่น']);
        $manager = $this->userWithRole('department-manager');
        $manager->update(['department_id' => $own->id]);
        User::factory()->create(['department_id' => $other->id]);
        $this->actingAs($manager)->get('/department?department_id='.$other->id)->assertInertia(fn (Assert $page) => $page->where('staff.total', 1)->where('staff.data.0.id', $manager->id)->missing('staff.data.0.username')->missing('staff.data.0.email'));
    }

    public function test_root_without_personal_department_can_view_all_staff_and_filter_departments(): void
    {
        $this->seed(RootAdministratorSeeder::class);
        $root = User::where('root_admin_key', 'PRIMARY')->firstOrFail();
        $root->update(['must_change_password' => false]);
        $first = Department::create(['code' => 'FIRST', 'name' => 'แผนกแรก']);
        $second = Department::create(['code' => 'SECOND', 'name' => 'แผนกที่สอง']);
        $person = User::factory()->create(['department_id' => $first->id]);
        User::factory()->create(['department_id' => $second->id]);
        $this->actingAs($root)->get('/department')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Department')->where('canSelectDepartments', true)->where('departmentName', 'ทุกแผนก')
            ->where('staff.total', 3)->has('departments', 2)->missing('staff.data.0.username')->missing('staff.data.0.email'));
        $this->get('/department?department_id='.$first->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('staff.total', 1)->where('staff.data.0.id', $person->id)->where('filters.department_id', $first->id)
            ->where('departmentName', $first->name)->where('staff.data.0.department.name', $first->name));
        $this->get('/department?department_id=999999')->assertRedirect()->assertSessionHasErrors('department_id');
        $this->assertNull($root->fresh()->department_id);
    }

    public function test_unassigned_department_manager_gets_empty_state_without_exposing_unassigned_staff(): void
    {
        $manager = $this->userWithRole('department-manager');
        User::factory()->create(['department_id' => null]);
        $this->actingAs($manager)->get('/department')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canSelectDepartments', false)->where('departmentName', 'ยังไม่ได้กำหนดแผนก')->where('staff.total', 0)->has('departments', 0));
    }

    public function test_organization_updates_are_audited_and_used_department_cannot_be_disabled(): void
    {
        $admin = $this->userWithRole('administrator');
        $this->actingAs($admin)->post('/admin/organization/departments', ['code' => 'NEW', 'name' => 'แผนกจำลอง', 'is_active' => true])->assertRedirect();
        $department = Department::query()->where('code', 'NEW')->firstOrFail();
        $admin->update(['department_id' => $department->id]);
        $this->put('/admin/organization/departments/'.$department->id, ['code' => 'NEW', 'name' => 'แผนกจำลอง', 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->assertTrue($department->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'departments.created']);
    }

    public function test_permission_changes_revoke_sessions_and_protected_admin_role_cannot_change(): void
    {
        $admin = $this->userWithRole('administrator');
        $instructor = $this->userWithRole('instructor');
        $role = Role::query()->where('code', 'instructor')->firstOrFail();
        $this->actingAs($admin)->put('/admin/roles/'.$role->id, ['code' => 'instructor', 'name' => 'ผู้คุมทดสอบ', 'permission_ids' => []])->assertRedirect();
        $this->assertSame(2, $instructor->fresh()->session_version);
        $adminRole = Role::query()->where('code', 'administrator')->firstOrFail();
        $this->put('/admin/roles/'.$adminRole->id, ['name' => 'ลบสิทธิ์', 'permission_ids' => []])->assertSessionHasErrors('permission_ids');
        $this->assertTrue($admin->hasPermission('roles.manage'));
    }

    public function test_delegated_user_manager_cannot_reset_admin_or_escalate_roles(): void
    {
        $admin = $this->userWithRole('administrator');
        $role = Role::create(['code' => 'limited-user-manager', 'name' => 'จัดการผู้ใช้จำกัด']);
        $role->permissions()->attach(Permission::query()->where('code', 'users.manage')->firstOrFail()->id);
        $limited = User::factory()->create();
        $limited->roles()->attach($role->id);
        $learner = $this->userWithRole('learner');
        $this->actingAs($limited)->post('/admin/users/'.$admin->id.'/reset-password', ['reason' => 'ไม่อนุญาต'])->assertForbidden();
        $payload = $this->userData($learner);
        $payload['role_ids'] = [Role::query()->where('code', 'administrator')->firstOrFail()->id];
        $this->put('/admin/users/'.$learner->id, $payload)->assertSessionHasErrors('role_ids');
        $this->assertFalse($learner->hasRole('administrator'));
    }

    public function test_settings_whitelist_does_not_allow_thaid_secret_injection(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->put('/admin/settings', ['organization_name' => 'องค์กรจำลองใหม่', 'session_timeout_minutes' => 60, 'THAID_CLIENT_SECRET' => 'must-not-store'])->assertRedirect();
        $this->assertDatabaseMissing('system_settings', ['key' => 'THAID_CLIENT_SECRET']);
        $this->assertStringNotContainsString('must-not-store', AuditLog::query()->get()->toJson());
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('app.organization', 'องค์กรจำลองใหม่'));
    }

    public function test_database_itself_rejects_non_digit_usernames(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert(['employee_code' => 'BAD', 'username' => '12ABC', 'password_hash' => 'not-a-login', 'first_name' => 'ทดสอบ', 'last_name' => 'ทดสอบ', 'display_name' => 'ทดสอบ']);
    }

    public function test_external_identity_subject_cannot_link_to_two_users(): void
    {
        $one = $this->userWithRole('learner');
        $two = $this->userWithRole('learner');
        ExternalIdentity::create(['user_id' => $one->id, 'provider' => 'thaid', 'issuer' => 'https://example.test', 'subject' => 'verified-synthetic-subject', 'verified_at' => now()]);
        $this->expectException(QueryException::class);
        ExternalIdentity::create(['user_id' => $two->id, 'provider' => 'thaid', 'issuer' => 'https://example.test', 'subject' => 'verified-synthetic-subject', 'verified_at' => now()]);
    }
}
