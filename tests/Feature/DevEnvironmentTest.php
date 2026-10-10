<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DevEnvironmentTest extends TestCase
{
    public function test_home_renders_the_inertia_vue_page(): void
    {
        $this->get('/dev')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('DevHome')
            ->where('laravelVersion', app()->version())
            ->where('phpVersion', PHP_VERSION)
        );
    }

    public function test_status_checks_real_mariadb_redis_and_file_storage(): void
    {
        $this->assertSame('smh_learning_test', DB::connection()->getDatabaseName());
        $this->getJson('/dev/status')->assertOk()->assertExactJson([
            'ok' => true,
            'checks' => ['database' => true, 'redis' => true, 'storage' => true],
        ]);
    }

    public function test_mariadb_transactions_commit_and_rollback_thai_data(): void
    {
        $this->assertSame('smh_learning_test', DB::connection()->getDatabaseName());
        DB::statement('CREATE TEMPORARY TABLE dev_transaction_probe (id INT PRIMARY KEY, label VARCHAR(100)) ENGINE=InnoDB');
        try {
            DB::transaction(fn () => DB::insert('INSERT INTO dev_transaction_probe VALUES (?, ?)', [1, 'ทดสอบภาษาไทย']));
            $this->assertSame('ทดสอบภาษาไทย', DB::scalar('SELECT label FROM dev_transaction_probe WHERE id = 1'));
            DB::beginTransaction();
            DB::insert('INSERT INTO dev_transaction_probe VALUES (?, ?)', [2, 'ต้องย้อนกลับ']);
            DB::rollBack();
            $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM dev_transaction_probe WHERE id = 2'));
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS dev_transaction_probe');
        }
    }
}
