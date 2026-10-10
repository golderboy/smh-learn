<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reward;
use App\Models\User;
use App\Models\WelfareStore;
use App\Services\FoundationSettings;
use App\Services\PointService;
use App\Services\WelfareService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrepareWelfareDemo extends Command
{
    protected $signature = 'dev:prepare-welfare-demo {--qa-credit : Add 3000 synthetic points with an audited unique entry for a browser QA run} {--ready : Restore only the demo learner balance to 3000 after QA with an audited adjustment}';

    protected $description = 'Prepare the approved demo shop, three coupons and synthetic demo learner points in local DEV only.';

    public function handle(WelfareService $service, PointService $points, FoundationSettings $settings): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('Restricted to local smh_learning_dev.');

            return self::FAILURE;
        }
        $admin = User::where('username', '0000000000001')->where('is_demo', true)->firstOrFail();
        $learner = User::where('username', '0000000000006')->where('is_demo', true)->firstOrFail();
        $operator = User::where('username', '0000000000010')->where('is_demo', true)->firstOrFail();
        // Keep the mandatory password-change rule even for the DEV fixture.
        $passwordFlag = $admin->must_change_password;
        if ($passwordFlag) {
            $this->error('Sign in as demo Admin and change the initial password before preparing coupons.');

            return self::FAILURE;
        }
        $request = Request::create('/dev/welfare-fixture', 'POST');
        $request->setUserResolver(fn () => $admin);
        $store = WelfareStore::where('code', 'DEMO-WELFARE')->first();
        if (! $store) {
            $store = $service->saveStore($request, ['code' => 'DEMO-WELFARE', 'name' => 'ร้านสวัสดิการ-'.$settings->organizationName(), 'contact' => 'ร้านจำลองสำหรับทดสอบในเครื่อง DEV', 'operator_ids' => [$operator->id]]);
            $service->setStoreStatus($request, $store, 'APPROVED', 'Admin อนุมัติร้านจำลองสำหรับทดสอบคูปองใน DEV');
        }
        if ($store->created_by !== $admin->id || ! $store->operators()->where('users.id', $operator->id)->exists()) {
            $this->error('Demo shop identity guard failed; existing assignments were not changed.');

            return self::FAILURE;
        }
        foreach ([[500, 500], [1000, 1000], [1500, 2000]] as [$cost, $discount]) {
            $name = 'ส่วนลดเครื่องดื่ม '.($discount / 100).' บาท';
            if (! Reward::where('welfare_store_id', $store->id)->where('name', $name)->exists()) {
                $service->saveReward($request, ['welfare_store_id' => $store->id, 'name' => $name, 'description' => 'คูปองทดสอบ ใช้เฉพาะร้านที่ระบุบนบัตร', 'required_points' => $cost, 'discount_satang' => $discount, 'inventory' => 100, 'is_active' => true, 'start_date' => null, 'end_date' => null]);
            }
        }
        $key = $this->option('qa-credit') ? (string) Str::uuid() : 'a0b75001-734c-4000-8000-000000000006';
        $reason = $this->option('qa-credit') ? 'แต้มจำลอง 3000 สำหรับ QA คูปองบน Browser DEV' : 'แต้มจำลอง 3000 สำหรับทดลองแลกคูปองสวัสดิการ DEV';
        $points->adjust($request, $learner, 3000, $reason, $key);
        if ($this->option('ready')) {
            $difference = 3000 - $points->balance($learner);
            if ($difference !== 0) {
                $points->adjust($request, $learner, $difference, 'คืนยอดแต้มจำลองเหลือ 3000 สำหรับผู้ใช้ทดลองหลัง QA คูปอง DEV', (string) Str::uuid());
            }
        }
        $this->line(json_encode(['store_id' => $store->id, 'operator_demo' => 'DEMO010', 'learner_demo' => 'DEMO006', 'balance' => $points->balance($learner), 'offers' => Reward::where('welfare_store_id', $store->id)->orderBy('required_points')->get(['id', 'required_points', 'discount_satang', 'inventory'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
