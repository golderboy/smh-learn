<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Models\WelfareClaim;
use App\Models\WelfareStore;
use App\Services\PointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class WelfareCouponTest extends FoundationTestCase
{
    public function test_offer_can_have_only_an_end_date_and_admin_stock_changes_are_bounded(): void
    {
        [$admin, , , $store] = $this->fixture();
        $this->loginAs($admin)->post('/admin/welfare/rewards', ['welfare_store_id' => $store->id, 'name' => 'รายการตั้งช่วงเปิดแลก', 'description' => null, 'required_points' => 600, 'discount_satang' => 1234, 'inventory' => 0, 'is_active' => false, 'start_date' => null, 'end_date' => today()->addMonth()->format('Y-m-d')])->assertRedirect()->assertSessionHasNoErrors();
        $offer = Reward::latest('id')->firstOrFail();
        $this->get('/admin/welfare')->assertInertia(fn (Assert $page) => $page->where('offers.1.start_date', null)->where('offers.1.end_date', today()->addMonth()->format('Y-m-d')));
        $this->post('/admin/welfare/rewards/'.$offer->id.'/stock', ['quantity' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $offer->fresh()->inventory);
        $this->post('/admin/welfare/rewards/'.$offer->id.'/stock', ['quantity' => 1000000])->assertSessionHasErrors('quantity');
        $this->assertSame(2, $offer->fresh()->inventory);
    }

    private function loginAs(User $user): static
    {
        // Switching simulated browser identities must start a fresh session version.
        $this->flushSession();

        return $this->actingAs($user->fresh());
    }

    /** @return array{User,User,User,WelfareStore,Reward} */
    private function fixture(int $balance = 3000, bool $approve = true): array
    {
        $admin = $this->userWithRole('administrator');
        $learner = $this->userWithRole('learner');
        $cashier = $this->userWithRole('learner');
        $this->loginAs($admin)->post('/admin/welfare/stores', ['code' => 'TEST-SHOP', 'name' => 'ร้านสวัสดิการ-ทดสอบ', 'contact' => null, 'operator_ids' => [$cashier->id]])->assertRedirect()->assertSessionHasNoErrors();
        $store = WelfareStore::firstOrFail();
        if ($approve) {
            $this->put('/admin/welfare/stores/'.$store->id.'/status', ['status' => 'APPROVED', 'reason' => 'อนุมัติร้านทดสอบ'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post('/admin/welfare/rewards', ['welfare_store_id' => $store->id, 'name' => 'ส่วนลดเครื่องดื่ม 5 บาท', 'description' => null, 'required_points' => 500, 'discount_satang' => 500, 'inventory' => 3, 'is_active' => true, 'start_date' => null, 'end_date' => null])->assertRedirect()->assertSessionHasNoErrors();
        $request = Request::create('/test/points', 'POST');
        $request->setUserResolver(fn () => $admin);
        app(PointService::class)->adjust($request, $learner, $balance, 'แต้มจำลองเพื่อทดสอบ', (string) Str::uuid());
        $this->flushSession();

        return [$admin, $learner, $cashier->fresh(), $store->fresh(), Reward::firstOrFail()];
    }

    /** @return array{request_key:string,expected_points:int,expected_discount_satang:int} */
    private function exchangeData(Reward $reward): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_points' => $reward->required_points, 'expected_discount_satang' => $reward->discount_satang];
    }

    private function issue(User $learner, Reward $reward): RewardRedemption
    {
        $this->loginAs($learner)->post('/coupons/rewards/'.$reward->id.'/exchange', $this->exchangeData($reward))->assertRedirect()->assertSessionHasNoErrors();

        return RewardRedemption::latest('id')->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function receiveData(RewardRedemption $coupon): array
    {
        return ['store_id' => $coupon->welfare_store_id, 'coupon_code' => $coupon->coupon_code, 'coupon_id' => $coupon->coupon_id, 'request_key' => (string) Str::uuid()];
    }

    /** @param list<RewardRedemption> $coupons
     * @return array<string,mixed> */
    private function claimData(WelfareStore $store, array $coupons): array
    {
        return ['store_id' => $store->id, 'coupon_ids' => array_map(fn ($coupon) => $coupon->coupon_id, $coupons), 'start_date' => today()->format('Y-m-d'), 'end_date' => today()->format('Y-m-d'), 'request_key' => (string) Str::uuid(), 'note' => 'รายการทดสอบส่งเบิก'];
    }

    public function test_admin_approval_and_store_assignment_are_required(): void
    {
        [$admin,$learner,$cashier,$store,$reward] = $this->fixture(3000, false);
        $this->assertTrue($cashier->hasPermission('welfare.receive'));
        $this->assertFalse($cashier->hasPermission('welfare.manage'));
        $this->loginAs($learner)->get('/admin/welfare')->assertForbidden();
        $this->get('/welfare/store')->assertForbidden();
        $this->post('/coupons/rewards/'.$reward->id.'/exchange', $this->exchangeData($reward))->assertSessionHasErrors('reward');
        $this->assertDatabaseCount('reward_redemptions', 0);
        $this->loginAs($cashier)->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => 'CPN1-'.str_repeat('A', 32)])->assertUnprocessable();
        $this->loginAs($admin)->get('/welfare/store?store_id='.$store->id)->assertForbidden();
        $this->loginAs($admin)->put('/admin/welfare/stores/'.$store->id.'/status', ['status' => 'APPROVED', 'reason' => 'ตรวจครบก่อนอนุมัติ'])->assertSessionHasNoErrors();
        $this->issue($learner, $reward);
    }

    public function test_exchange_is_atomic_encrypted_and_retry_does_not_spend_twice(): void
    {
        $this->travelTo(now()->startOfSecond());
        [, $learner, , , $reward] = $this->fixture();
        $data = $this->exchangeData($reward);
        $this->loginAs($learner)->post('/coupons/rewards/'.$reward->id.'/exchange', $data)->assertRedirect()->assertSessionHasNoErrors();
        $coupon = RewardRedemption::firstOrFail();
        $this->assertSame(2500, app(PointService::class)->balance($learner));
        $this->assertSame(2, $reward->fresh()->inventory);
        $this->assertSame(-500, PointTransaction::where('transaction_type', 'REDEEM_REWARD')->firstOrFail()->points);
        $this->assertSame(now()->addDays(90)->timestamp, $coupon->expires_at->timestamp);
        $this->assertMatchesRegularExpression('/^CPN1-[A-F0-9]{32}$/', $coupon->coupon_code);
        $this->assertSame(hash('sha256', $coupon->coupon_code), $coupon->coupon_hash);
        $this->assertNotSame($coupon->coupon_code, DB::table('reward_redemptions')->where('id', $coupon->id)->value('coupon_code'));
        $this->assertArrayNotHasKey('coupon_code', $coupon->toArray());
        $this->post('/coupons/rewards/'.$reward->id.'/exchange', $data)->assertRedirect('/coupons/'.$coupon->coupon_id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reward_redemptions', 1);
        $this->assertSame(2500, app(PointService::class)->balance($learner));
        $data['expected_points'] = 501;
        $this->post('/coupons/rewards/'.$reward->id.'/exchange', $data)->assertConflict();
        $audit = AuditLog::where('action', 'welfare.coupon_issued')->firstOrFail()->toJson();
        $this->assertStringNotContainsString($coupon->coupon_code, $audit);
        $this->get('/coupons')->assertInertia(fn (Assert $page) => $page->component('Welfare/Catalog')->has('coupons.data', 1)->missing('coupons.data.0.coupon_code')->missing('coupons.data.0.coupon_hash'));
        $this->get('/coupons/'.$coupon->coupon_id)->assertInertia(fn (Assert $page) => $page->component('Welfare/Card')->where('coupon.coupon_code', $coupon->coupon_code));
        $other = $this->userWithRole('learner');
        $this->loginAs($other)->get('/coupons/'.$coupon->coupon_id)->assertForbidden();
    }

    public function test_insufficient_points_stock_price_and_dates_reject_without_partial_spending(): void
    {
        [, $learner,,, $reward] = $this->fixture(499);
        $this->loginAs($learner)->post('/coupons/rewards/'.$reward->id.'/exchange', $this->exchangeData($reward))->assertSessionHasErrors('reward');
        $this->assertSame(499, app(PointService::class)->balance($learner));
        $this->assertSame(3, $reward->fresh()->inventory);
        $this->assertDatabaseCount('reward_redemptions', 0);
        foreach ([['inventory' => 0], ['inventory' => 3, 'is_active' => false], ['is_active' => true, 'start_date' => today()->addDay()], ['start_date' => null, 'end_date' => today()->subDay()]] as $change) {
            $reward->update($change);
            $this->post('/coupons/rewards/'.$reward->id.'/exchange', $this->exchangeData($reward))->assertSessionHasErrors('reward');
        }
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('reward_redemptions', 0);
    }

    public function test_changed_offer_requires_review_and_issued_coupon_keeps_its_price(): void
    {
        [$admin,$learner,$cashier,$store,$reward] = $this->fixture();
        $coupon = $this->issue($learner, $reward);
        $oldData = $this->exchangeData($reward);
        $this->loginAs($admin)->put('/admin/welfare/rewards/'.$reward->id, ['welfare_store_id' => $store->id, 'name' => 'ชื่อใหม่', 'description' => null, 'required_points' => 900, 'discount_satang' => 999, 'inventory' => 999, 'is_active' => true, 'start_date' => null, 'end_date' => null])->assertSessionHasNoErrors();
        $this->assertSame(2, $reward->fresh()->inventory);
        $this->loginAs($learner)->post('/coupons/rewards/'.$reward->id.'/exchange', $oldData)->assertSessionHasErrors('reward');
        $this->assertSame(2500, app(PointService::class)->balance($learner));
        $this->loginAs($cashier)->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => $coupon->coupon_code])->assertOk()->assertJsonPath('coupon.discount_satang', 500)->assertJsonPath('coupon.reward_name', 'ส่วนลดเครื่องดื่ม 5 บาท');
    }

    public function test_store_preview_does_not_consume_and_receive_is_one_use_with_safe_retry(): void
    {
        [, $learner,$cashier,$store,$reward] = $this->fixture();
        $coupon = $this->issue($learner, $reward);
        $data = $this->receiveData($coupon);
        $this->loginAs($cashier)->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => strtolower($coupon->coupon_code)])->assertOk()->assertJsonMissingPath('coupon.coupon_code');
        $this->assertSame('APPROVED', $coupon->fresh()->status);
        $this->post('/welfare/coupons/receive', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/welfare/coupons/receive', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('RECEIVED', $coupon->fresh()->status);
        $this->assertSame($cashier->id, $coupon->fresh()->received_by);
        $data['request_key'] = (string) Str::uuid();
        $this->post('/welfare/coupons/receive', $data)->assertSessionHasErrors('coupon_code');
        $this->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => $coupon->coupon_code])->assertUnprocessable();
        $this->assertSame(1, AuditLog::where('action', 'welfare.coupon_received')->count());
        $this->assertSame(2500, app(PointService::class)->balance($learner));
        $this->assertFalse(session()->has('_old_input.coupon_code'));
        $this->loginAs($learner)->get('/coupons/'.$coupon->coupon_id)->assertInertia(fn (Assert $page) => $page->where('coupon.status', 'RECEIVED')->where('coupon.coupon_code', null));
    }

    public function test_forged_wrong_store_expired_inactive_holder_and_suspended_shop_are_rejected(): void
    {
        [$admin,$learner,$cashier,$store,$reward] = $this->fixture();
        $coupon = $this->issue($learner, $reward);
        $this->loginAs($admin)->post('/admin/welfare/stores', ['code' => 'OTHER', 'name' => 'ร้านอื่น', 'contact' => null, 'operator_ids' => [$cashier->id]])->assertSessionHasNoErrors();
        $other = WelfareStore::where('code', 'OTHER')->firstOrFail();
        $this->put('/admin/welfare/stores/'.$other->id.'/status', ['status' => 'APPROVED', 'reason' => 'อนุมัติร้านอื่นทดสอบ'])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->loginAs($cashier->fresh())->postJson('/welfare/coupons/check', ['store_id' => $other->id, 'coupon_code' => $coupon->coupon_code])->assertUnprocessable();
        $this->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => 'CPN1-'.str_repeat('B', 32)])->assertUnprocessable();
        $this->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => $coupon->coupon_id])->assertUnprocessable();
        $coupon->update(['expires_at' => now()]);
        $this->post('/welfare/coupons/receive', $this->receiveData($coupon))->assertSessionHasErrors('coupon_code');
        $coupon->update(['expires_at' => now()->addDay()]);
        $learner->update(['is_active' => false]);
        $this->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => $coupon->coupon_code])->assertUnprocessable();
        $learner->update(['is_active' => true]);
        $this->loginAs($admin)->put('/admin/welfare/stores/'.$store->id.'/status', ['status' => 'SUSPENDED', 'reason' => 'ระงับเพื่อทดสอบ'])->assertSessionHasNoErrors();
        $this->loginAs($cashier->fresh())->post('/welfare/coupons/receive', $this->receiveData($coupon))->assertSessionHasErrors('coupon_code');
        $this->assertSame('APPROVED', $coupon->fresh()->status);
    }

    public function test_unassigned_cashier_and_revoked_permission_cannot_receive_or_report(): void
    {
        [$admin,$learner,$cashier,$store,$reward] = $this->fixture();
        $coupon = $this->issue($learner, $reward);
        $otherCashier = $this->userWithRole('welfare-shop');
        $this->loginAs($otherCashier)->postJson('/welfare/coupons/check', ['store_id' => $store->id, 'coupon_code' => $coupon->coupon_code])->assertForbidden();
        $this->get('/welfare/store?store_id='.$store->id)->assertForbidden();
        $this->loginAs($admin)->put('/admin/welfare/stores/'.$store->id, ['name' => $store->name, 'contact' => null, 'operator_ids' => [$otherCashier->id]])->assertSessionHasNoErrors();
        $this->assertFalse($cashier->fresh()->hasPermission('welfare.receive'));
        $this->loginAs($cashier->fresh())->post('/welfare/coupons/receive', $this->receiveData($coupon))->assertForbidden();
    }

    public function test_three_example_offers_have_correct_points_discount_and_statement_total(): void
    {
        [$admin,$learner,$cashier,$store,$reward] = $this->fixture();
        $coupons = [$this->issue($learner, $reward)];
        foreach ([[1000, 1000], [1500, 2000]] as [$cost,$discount]) {
            $this->loginAs($admin)->post('/admin/welfare/rewards', ['welfare_store_id' => $store->id, 'name' => 'ส่วนลดเครื่องดื่ม '.($discount / 100).' บาท', 'description' => null, 'required_points' => $cost, 'discount_satang' => $discount, 'inventory' => 3, 'is_active' => true, 'start_date' => null, 'end_date' => null])->assertSessionHasNoErrors();
            $coupons[] = $this->issue($learner, Reward::latest('id')->firstOrFail());
        }
        $this->assertSame(0, app(PointService::class)->balance($learner));
        foreach ($coupons as $coupon) {
            $this->loginAs($cashier)->post('/welfare/coupons/receive', $this->receiveData($coupon))->assertSessionHasNoErrors();
        }
        $claimData = $this->claimData($store, $coupons);
        $this->post('/welfare/claims', $claimData)->assertRedirect()->assertSessionHasNoErrors();
        $claim = WelfareClaim::firstOrFail();
        $this->assertSame(3500, $claim->total_satang);
        $this->assertSame(3, $claim->coupon_count);
        $this->assertDatabaseCount('welfare_claim_items', 3);
        $this->post('/welfare/claims', $claimData)->assertRedirect('/welfare/claims/'.$claim->claim_id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('welfare_claims', 1);
        $claimData['request_key'] = (string) Str::uuid();
        $this->post('/welfare/claims', $claimData)->assertSessionHasErrors('coupon_ids');
        $csv = $this->get('/welfare/claims/'.$claim->claim_id.'/csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('35.00', $csv);
        foreach ($coupons as $coupon) {
            $this->assertStringNotContainsString($coupon->coupon_code, $csv);
        }
        $pdf = $this->get('/welfare/claims/'.$claim->claim_id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->get('/welfare/store?store_id='.$store->id)->assertInertia(fn (Assert $page) => $page->where('summary.total_satang', 3500)->where('summary.unclaimed_count', 0)->where('coupons.data.0.claim_id', $claim->claim_id));
        $this->loginAs($learner)->get('/welfare/claims/'.$claim->claim_id.'/pdf')->assertForbidden();
        $this->loginAs($admin)->get('/welfare/claims/'.$claim->claim_id)->assertOk();
    }

    public function test_claim_only_accepts_used_unclaimed_coupons_inside_the_selected_period(): void
    {
        [, $learner,$cashier,$store,$reward] = $this->fixture();
        $first = $this->issue($learner, $reward);
        $second = $this->issue($learner, $reward);
        $this->loginAs($cashier)->post('/welfare/coupons/receive', $this->receiveData($first))->assertSessionHasNoErrors();
        $this->post('/welfare/claims', $this->claimData($store, [$first, $second]))->assertSessionHasErrors('coupon_ids');
        $this->assertDatabaseCount('welfare_claims', 0);
        $data = $this->claimData($store, [$first]);
        $data['start_date'] = today()->subDay()->format('Y-m-d');
        $data['end_date'] = $data['start_date'];
        $this->post('/welfare/claims', $data)->assertSessionHasErrors('coupon_ids');
        $data = $this->claimData($store, [$first]);
        $this->post('/welfare/claims', $data)->assertSessionHasNoErrors();
        $data['note'] = 'แก้รายละเอียดคำขอเดิม';
        $this->post('/welfare/claims', $data)->assertConflict();
        $this->assertDatabaseCount('welfare_claim_items', 1);
    }

    public function test_claim_snapshot_and_csv_formula_safety_are_preserved(): void
    {
        [$admin,$learner,$cashier,$store,$reward] = $this->fixture();
        $learner->update(['display_name' => '=HYPERLINK("unsafe")']);
        $coupon = $this->issue($learner, $reward);
        $this->loginAs($cashier)->post('/welfare/coupons/receive', $this->receiveData($coupon))->assertSessionHasNoErrors();
        $this->post('/welfare/claims', $this->claimData($store, [$coupon]))->assertSessionHasNoErrors();
        $claim = WelfareClaim::firstOrFail();
        $store->update(['name' => 'ชื่อร้านใหม่']);
        $learner->update(['display_name' => 'ชื่อเปลี่ยน']);
        $reward->update(['discount_satang' => 999]);
        $this->assertSame('ร้านสวัสดิการ-ทดสอบ', $claim->fresh()->store_name);
        $this->assertSame(500, $claim->items()->firstOrFail()->discount_satang);
        $csv = $this->get('/welfare/claims/'.$claim->claim_id.'/csv')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('ชื่อเปลี่ยน', $csv);
        $this->loginAs($this->userWithRole('welfare-shop'))->get('/welfare/claims/'.$claim->claim_id)->assertForbidden();
        $this->expectException(\LogicException::class);
        $claim->update(['total_satang' => 999]);
    }
}
