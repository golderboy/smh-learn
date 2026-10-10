<?php

declare(strict_types=1);

use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WelfareClaim;
use App\Models\WelfareStore;
use App\Services\PointService;
use App\Services\WelfareService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

// Standalone real-process probe. It never runs against DEV or production.
putenv('APP_ENV=testing');
putenv('DB_DATABASE=smh_learning_test');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'smh_learning_test';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Preserve a nonzero exit code if a standalone probe assertion fails.
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Welfare concurrency probe failed: '.get_class($exception)."\n");
    exit(1);
});
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'smh_learning_test' || DB::transactionLevel() !== 0) {
    throw new RuntimeException('Concurrent probe requires isolated smh_learning_test and committed fixtures.');
}

function actorRequest(User $actor): Request
{
    $request = Request::create('/test/concurrent-coupon', 'POST');
    $request->setUserResolver(fn () => $actor);

    return $request;
}
function requireProbe(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$service = app(WelfareService::class);
if (($argv[1] ?? '') === 'worker') {
    $payload = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $actor = User::whereKey($payload['actor'])->where('employee_code', 'like', 'CONC-%')->firstOrFail();
    $store = WelfareStore::whereKey($payload['store'])->where('code', 'like', 'CONC-%')->firstOrFail();
    echo "READY\n";
    flush();
    try {
        if ($payload['mode'] === 'issue') {
            $reward = Reward::where('welfare_store_id', $store->id)->firstOrFail();
            $service->issue(actorRequest($actor), $reward, $payload['key'], 500, 500);
        } elseif ($payload['mode'] === 'receive') {
            $coupon = RewardRedemption::where('welfare_store_id', $store->id)->firstOrFail();
            $service->receive(actorRequest($actor), $store->id, $coupon->coupon_code, $coupon->coupon_id, $payload['key']);
        } else {
            $coupon = RewardRedemption::where('welfare_store_id', $store->id)->firstOrFail();
            $service->claim(actorRequest($actor), $store->id, [$coupon->coupon_id], today()->format('Y-m-d'), today()->format('Y-m-d'), $payload['key'], 'Concurrent probe');
        }
        echo "ACCEPTED\n";
    } catch (ValidationException) {
        echo "REJECTED\n";
    }
    exit(0);
}

$users = [];
$store = null;
$results = [];
try {
    $app->make(FoundationSeeder::class)->run();
    foreach (['administrator', 'learner', 'welfare-shop'] as $roleCode) {
        $user = User::factory()->create(['employee_code' => 'CONC-'.Str::uuid(), 'is_demo' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail()->id);
        $users[] = $user;
    }
    [$admin,$learner,$cashier] = $users;
    $store = $service->saveStore(actorRequest($admin), ['code' => 'CONC-'.substr((string) Str::uuid(), 0, 30), 'name' => 'ร้านจำลอง concurrent probe', 'contact' => null, 'operator_ids' => [$cashier->id]]);
    $service->setStoreStatus(actorRequest($admin), $store, 'APPROVED', 'อนุมัติร้านจำลองในฐานข้อมูลทดสอบ');
    $reward = $service->saveReward(actorRequest($admin), ['welfare_store_id' => $store->id, 'name' => 'คูปองทดสอบพร้อมกัน', 'description' => null, 'required_points' => 500, 'discount_satang' => 500, 'inventory' => 1, 'is_active' => true]);
    app(PointService::class)->adjust(actorRequest($admin), $learner, 500, 'แต้มทดสอบพร้อมกัน', (string) Str::uuid());

    foreach (['issue', 'receive', 'claim'] as $mode) {
        $processes = [];
        DB::beginTransaction();
        SystemSetting::where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
        for ($number = 0; $number < 2; $number++) {
            $payload = ['mode' => $mode, 'actor' => $mode === 'issue' ? $learner->id : $cashier->id, 'store' => $store->id, 'key' => (string) Str::uuid()];
            $process = new Process([PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], base_path(), null, null, 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (! str_contains($processes[0]->getOutput(), 'READY') || ! str_contains($processes[1]->getOutput(), 'READY')) {
            requireProbe(microtime(true) < $deadline, 'Workers did not initialize within 15 seconds.');
            usleep(20000);
        }
        // Both independent PHP workers attempt their database transaction while this lock is held.
        usleep(400000);
        DB::commit();
        foreach ($processes as $process) {
            $process->wait();
            requireProbe($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
        }
        $output = implode('', array_map(fn (Process $process) => $process->getOutput(), $processes));
        requireProbe(substr_count($output, 'ACCEPTED') === 1 && substr_count($output, 'REJECTED') === 1, 'Concurrent '.$mode.' must have exactly one winner.');
        $results[$mode] = ['parallel_workers' => 2, 'accepted' => 1, 'rejected' => 1];
    }
    requireProbe(app(PointService::class)->balance($learner) === 0, 'Balance must be exactly zero.');
    requireProbe(PointTransaction::where('user_id', $learner->id)->where('transaction_type', 'REDEEM_REWARD')->count() === 1, 'Exactly one debit required.');
    requireProbe($reward->fresh()->inventory === 0, 'Exactly one stock decrement required.');
    requireProbe(RewardRedemption::where('welfare_store_id', $store->id)->count() === 1, 'Exactly one coupon required.');
    requireProbe(WelfareClaim::where('welfare_store_id', $store->id)->count() === 1, 'Exactly one claim required.');
    echo json_encode(['database' => 'smh_learning_test', 'result' => 'PASS', 'scenarios' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Cleanup is limited to these freshly generated synthetic IDs in the isolated test database.
    if ($store) {
        $claims = DB::table('welfare_claims')->where('welfare_store_id', $store->id)->pluck('id');
        DB::table('welfare_claim_items')->whereIn('welfare_claim_id', $claims)->delete();
        DB::table('welfare_claims')->whereIn('id', $claims)->delete();
        DB::table('reward_redemptions')->where('welfare_store_id', $store->id)->delete();
        DB::table('rewards')->where('welfare_store_id', $store->id)->delete();
        DB::table('welfare_store_users')->where('welfare_store_id', $store->id)->delete();
        DB::table('welfare_stores')->where('id', $store->id)->delete();
    }
    $ids = array_map(fn (User $user) => $user->id, $users);
    DB::table('audit_logs')->whereIn('user_id', $ids)->delete();
    DB::table('point_transactions')->whereIn('user_id', $ids)->delete();
    DB::table('user_roles')->whereIn('user_id', $ids)->delete();
    DB::table('users')->whereIn('id', $ids)->where('employee_code', 'like', 'CONC-%')->delete();
}
