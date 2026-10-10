<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InitializeFoundation extends Command
{
    protected $signature = 'dev:initialize-foundation';

    protected $description = 'Run additive migrations and seed ten users in this local DEV database only.';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('This command is restricted to local smh_learning_dev.');

            return self::FAILURE;
        }
        if ($this->call('dev:prepare-storage') !== self::SUCCESS) {
            return self::FAILURE;
        }
        if (! Schema::hasTable('migrations') && count(DB::select('SHOW TABLES')) !== 0) {
            $this->error('An untracked schema already exists; inspect it before running migrations.');

            return self::FAILURE;
        }
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('dev:seed-demo');
    }
}
