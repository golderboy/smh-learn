<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCategory;
use App\Models\Department;
use App\Models\ExternalIdentity;
use App\Models\Position;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FoundationService
{
    public function __construct(private AuditService $audit) {}

    private function lock(): void
    {
        // Serialize privilege changes so two admins cannot disable the last admin together.
        SystemSetting::query()->where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $actor = $actor->fresh();
        abort_unless($actor instanceof User && $actor->is_active && $actor->hasPermission($permission), 403);

        return $actor;
    }

    /** @param array<string, mixed> $data */
    public function saveUser(Request $request, array $data, ?User $existing = null): User
    {
        return DB::transaction(function () use ($request, $data, $existing): User {
            $this->lock();
            $actor = $this->actor($request, 'users.manage');
            $user = $existing ? User::query()->lockForUpdate()->findOrFail($existing->id) : new User;
            if ($existing) {
                Gate::forUser($actor)->authorize('update', $user);
            }
            $old = $existing ? $user->only(['employee_code', 'username', 'first_name', 'last_name', 'department_id', 'position_id', 'employment_status', 'is_active']) : null;
            $oldRoleIds = $existing ? $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all() : [];
            $roleIds = array_map('intval', $data['role_ids'] ?? ($existing ? $oldRoleIds : [Role::query()->where('code', 'learner')->firstOrFail()->id]));
            sort($oldRoleIds);
            sort($roleIds);
            $learnerRoleId = Role::query()->where('code', 'learner')->firstOrFail()->id;
            $rolesChanged = $existing ? $oldRoleIds !== $roleIds : $roleIds !== [$learnerRoleId];
            if ($rolesChanged && ! $actor->hasPermission('roles.manage')) {
                throw ValidationException::withMessages(['role_ids' => 'คุณไม่มีสิทธิ์กำหนดบทบาท']);
            }
            if (Role::query()->whereIn('id', $roleIds)->where('code', 'administrator')->exists() && ! $actor->hasRole('administrator')) {
                throw ValidationException::withMessages(['role_ids' => 'เฉพาะผู้ดูแลระบบที่กำหนดบทบาทผู้ดูแลระบบได้']);
            }
            if (Role::query()->whereIn('id', $roleIds)->where('code', 'department-manager')->exists() && empty($data['department_id'])) {
                throw ValidationException::withMessages(['department_id' => 'หัวหน้าแผนกต้องมีแผนกที่รับผิดชอบ']);
            }
            $user->fill(collect($data)->except(['role_ids'])->all());
            if ($existing && $user->isDirty('username') && ExternalIdentity::query()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['username' => 'บัญชีเชื่อมตัวตนภายนอกแล้ว ต้องตรวจสอบการเชื่อมก่อนเปลี่ยนเลขประจำตัว']);
            }
            $user->display_name = trim($data['first_name'].' '.$data['last_name']);
            if (! $existing) {
                $user->password_hash = (string) config('identity.initial_password');
                $user->must_change_password = (bool) config('identity.require_initial_password_change');
            } elseif ($user->isDirty(['is_active', 'username']) || $oldRoleIds !== $roleIds) {
                $user->session_version++;
            }
            $user->save();
            $user->roles()->sync($roleIds);
            if (! $user->hasRole('instructor')) {
                $user->supervisedCategories()->detach();
            }
            $this->requireAdministrator();
            $new = $user->only(['employee_code', 'username', 'first_name', 'last_name', 'department_id', 'position_id', 'employment_status', 'is_active']);
            $new['role_ids'] = $roleIds;
            if ($old !== null) {
                $old['role_ids'] = $oldRoleIds;
            }
            $this->audit->record($request, $existing ? 'user.updated' : 'user.created', 'user', $user->id, $old, $new);
            if ($actor->id === $user->id) {
                $request->session()->put('auth_version', $user->session_version);
            }

            return $user;
        });
    }

    private function requireAdministrator(): void
    {
        if (! User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('code', 'administrator'))->exists()) {
            throw ValidationException::withMessages(['is_active' => 'ต้องมีผู้ดูแลระบบที่เปิดใช้งานอย่างน้อยหนึ่งคน']);
        }
    }

    public function resetPassword(Request $request, User $target, string $reason): void
    {
        Gate::authorize('update', $target);
        DB::transaction(function () use ($request, $target, $reason): void {
            $this->lock();
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($this->actor($request, 'users.manage'))->authorize('update', $user);
            $user->password_hash = (string) config('identity.initial_password');
            $user->must_change_password = (bool) config('identity.require_initial_password_change');
            $user->session_version++;
            $user->remember_token = null;
            $user->save();
            $this->audit->record($request, 'user.password_reset', 'user', $user->id, reason: $reason);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveOrganization(Request $request, string $kind, array $data, ?int $id = null): void
    {
        DB::transaction(function () use ($request, $kind, $data, $id): void {
            $this->lock();
            $this->actor($request, 'organization.manage');
            $model = $kind === 'departments' ? new Department : new Position;
            $record = $id === null ? $model : $model->newQuery()->lockForUpdate()->findOrFail($id);
            $old = $id === null ? null : $record->only(['code', 'name', 'is_active']);
            $foreignKey = $kind === 'departments' ? 'department_id' : 'position_id';
            if ($id !== null && ! $data['is_active'] && User::query()->where($foreignKey, $id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'มีบุคลากรที่เปิดใช้งานอยู่ กรุณาย้ายหรือปิดบัญชีก่อน']);
            }
            $record->fill($data)->save();
            $this->audit->record($request, $kind.'.'.($id === null ? 'created' : 'updated'), $kind, $record->id, $old, $record->only(['code', 'name', 'is_active']));
        });
    }

    /** @param array<string, mixed> $data */
    public function saveCategory(Request $request, array $data, ?CourseCategory $existing = null): CourseCategory
    {
        return DB::transaction(function () use ($request, $data, $existing): CourseCategory {
            $this->lock();
            $actor = $this->actor($request, 'categories.manage');
            $category = $existing ? CourseCategory::query()->lockForUpdate()->findOrFail($existing->id) : new CourseCategory;
            $old = $existing ? $category->only(['code', 'name', 'description', 'is_active']) : null;
            $oldIds = $existing ? $category->instructors()->pluck('users.id')->all() : [];
            $category->fill(collect($data)->except('instructor_ids')->all());
            if (! $existing) {
                $category->created_by = $actor->id;
            }
            $category->save();
            $ids = array_map('intval', $data['instructor_ids'] ?? []);
            if (User::query()->whereIn('id', $ids)->where('is_active', true)->count() !== count($ids)) {
                throw ValidationException::withMessages(['instructor_ids' => 'เลือกเฉพาะบัญชีที่เปิดใช้งาน และไม่เลือกซ้ำ']);
            }
            $instructorRole = Role::query()->where('code', 'instructor')->firstOrFail();
            foreach (User::query()->whereIn('id', $ids)->get() as $instructor) {
                $instructor->roles()->syncWithoutDetaching([$instructorRole->id]);
            }
            $assignments = [];
            foreach ($ids as $userId) {
                $assignments[$userId] = ['assigned_by' => $actor->id];
            }
            $category->instructors()->sync($assignments);
            $new = $category->only(['code', 'name', 'description', 'is_active']);
            $new['instructor_ids'] = $ids;
            if ($old !== null) {
                $old['instructor_ids'] = $oldIds;
            }
            $this->audit->record($request, $existing ? 'category.updated' : 'category.created', 'course_category', $category->id, $old, $new);

            return $category;
        });
    }

    /** @param array<string, mixed> $data */
    public function saveRole(Request $request, array $data, ?Role $existing = null): void
    {
        DB::transaction(function () use ($request, $data, $existing): void {
            $this->lock();
            $this->actor($request, 'roles.manage');
            $role = $existing ? Role::query()->lockForUpdate()->findOrFail($existing->id) : new Role;
            if ($role->code === 'administrator') {
                throw ValidationException::withMessages(['permission_ids' => 'บทบาทผู้ดูแลระบบสงวนสิทธิ์ครบทุกส่วน']);
            }
            $old = $existing ? ['name' => $role->name, 'permission_ids' => $role->permissions()->pluck('permissions.id')->all()] : null;
            if (! $existing) {
                $role->code = $data['code'];
            }
            $role->name = $data['name'];
            $role->save();
            $role->permissions()->sync($data['permission_ids']);
            User::query()->whereHas('roles', fn ($query) => $query->where('roles.id', $role->id))->increment('session_version');
            $this->audit->record($request, $existing ? 'role.updated' : 'role.created', 'role', $role->id, $old, ['name' => $role->name, 'permission_ids' => $data['permission_ids']]);
        });
    }

    /** @param array<string, mixed> $data */
    public function saveSettings(Request $request, array $data): void
    {
        DB::transaction(function () use ($request, $data): void {
            $this->lock();
            $actor = $this->actor($request, 'settings.manage');
            foreach ($data as $key => $value) {
                $setting = SystemSetting::query()->firstOrCreate(['key' => $key], ['value' => ['value' => null]]);
                $old = $setting->value;
                $setting->update(['value' => ['value' => $value], 'updated_by' => $actor->id]);
                $this->audit->record($request, 'setting.updated', 'system_setting', $setting->id, $old, ['value' => $value]);
            }
        });
    }
}
