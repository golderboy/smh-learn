<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class RootAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            SystemSetting::where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
            $root = User::where('root_admin_key', 'PRIMARY')->first();
            if ($root !== null) {
                if ($root->username !== 'admins' || ! $root->is_active || ! $root->hasRole('administrator')) {
                    throw new LogicException('Protected administrator requires a database recovery review.');
                }

                return; // Never reset a password or overwrite an established root account.
            }
            if (User::where('username', 'admins')->orWhere('employee_code', 'SYSTEM-ROOT')->exists()) {
                throw new LogicException('Reserved administrator identifier already exists.');
            }
            $root = new User;
            $root->fill(['employee_code' => 'SYSTEM-ROOT', 'username' => 'admins', 'first_name' => 'ผู้ดูแลระบบ', 'last_name' => 'ตั้งต้น', 'display_name' => 'ผู้ดูแลระบบตั้งต้น', 'password_hash' => 'P@ssw0rd112233', 'is_active' => true, 'is_demo' => false, 'must_change_password' => true]);
            $root->root_admin_key = 'PRIMARY';
            $root->save();
            $root->roles()->attach(Role::where('code', 'administrator')->firstOrFail()->id);
        });
    }
}
