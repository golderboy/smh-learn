<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\PermissionCatalog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $code => [$name, $module]) {
            Permission::firstOrCreate(['code' => $code], ['name' => $name, 'module' => $module]);
        }
        foreach (PermissionCatalog::roles() as $code => $definition) {
            $role = Role::firstOrCreate(['code' => $code], ['name' => $definition['name'], 'is_system' => true]);
            if ($role->wasRecentlyCreated || $code === 'administrator') {
                $role->permissions()->sync(Permission::query()->whereIn('code', $definition['permissions'])->pluck('id')->all());
            }
        }
        foreach (['organization_name' => 'องค์กรของฉัน', 'session_timeout_minutes' => 120, 'foundation_write_lock' => true] as $key => $value) {
            SystemSetting::firstOrCreate(['key' => $key], ['value' => ['value' => $value]]);
        }
    }
}
