<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedDemoUsers extends Command
{
    protected $signature = 'dev:seed-demo {--reset-passwords : Reset only the ten designated demo accounts for repeated DEV testing}';

    protected $description = 'Create ten synthetic users only in the SMH local DEV database.';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('This command is restricted to local smh_learning_dev.');

            return self::FAILURE;
        }
        if ($this->call('db:seed', ['--class' => DemoUserSeeder::class]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->option('reset-passwords')) {
            DB::transaction(function (): void {
                for ($number = 1; $number <= 10; $number++) {
                    $user = User::query()->where('username', str_pad((string) $number, 13, '0', STR_PAD_LEFT))->where('is_demo', true)->lockForUpdate()->firstOrFail();
                    $user->password_hash = (string) config('identity.initial_password');
                    $user->must_change_password = (bool) config('identity.require_initial_password_change');
                    $user->session_version++;
                    $user->remember_token = null;
                    $user->save();
                }
            });
        }
        $this->info('Ten synthetic DEV accounts are available. See the DEV guide for their roles.');

        return self::SUCCESS;
    }
}
