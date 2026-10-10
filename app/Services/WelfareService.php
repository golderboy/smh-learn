<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WelfareClaim;
use App\Models\WelfareStore;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WelfareService
{
    public function __construct(private readonly PointService $points, private readonly AuditService $audit) {}

    private function lock(): void
    {
        // Share the privilege-change lock with FoundationService; recheck roles inside it.
        SystemSetting::where('key', 'foundation_write_lock')->lockForUpdate()->firstOrFail();
    }

    public function actor(Request $request, ?string $permission = null, bool $lock = false): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $actor = User::whereKey($user->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($actor->is_active && ! $actor->must_change_password, 403);
        if ($request->hasSession() && $request->session()->has('auth_version')) {
            abort_unless((int) $request->session()->get('auth_version') === $actor->session_version, 403);
        }
        abort_unless($permission === null || $actor->hasPermission($permission), 403);

        return $actor;
    }

    public function storeFor(User $actor, int $id, string $permission, bool $lock = false): WelfareStore
    {
        abort_unless($actor->hasPermission($permission), 403);
        $store = WelfareStore::whereKey($id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        // Administration never silently bypasses the cashier's store assignment.
        abort_unless($store->operators()->where('users.id', $actor->id)->exists(), 403);

        return $store;
    }

    /** @param array<string, mixed> $data */
    public function saveStore(Request $request, array $data, ?WelfareStore $existing = null): WelfareStore
    {
        return DB::transaction(function () use ($request, $data, $existing): WelfareStore {
            $this->lock();
            $actor = $this->actor($request, 'welfare.manage', true);
            $store = $existing ? WelfareStore::whereKey($existing->id)->lockForUpdate()->firstOrFail() : new WelfareStore;
            $old = $existing ? $store->only(['name', 'contact', 'status']) : null;
            $oldOperators = $existing ? $store->operators()->pluck('users.id')->all() : [];
            $ids = array_map('intval', $data['operator_ids']);
            if (User::whereIn('id', $ids)->where('is_active', true)->count() !== count($ids)) {
                throw ValidationException::withMessages(['operator_ids' => 'เลือกผู้ปฏิบัติงานที่เปิดใช้งานอยู่เท่านั้น']);
            }
            $store->fill(['name' => $data['name'], 'contact' => $data['contact'] ?? null]);
            if (! $existing) {
                $store->fill(['code' => $data['code'], 'created_by' => $actor->id, 'status' => 'PENDING']);
            }
            $store->save();
            $store->operators()->sync(array_fill_keys($ids, ['assigned_by' => $actor->id]));
            $role = Role::where('code', 'welfare-shop')->firstOrFail();
            foreach (array_unique([...$oldOperators, ...$ids]) as $id) {
                $operator = User::whereKey($id)->lockForUpdate()->firstOrFail();
                if (in_array($id, $ids, true)) {
                    $operator->roles()->syncWithoutDetaching([$role->id]);
                } elseif (! DB::table('welfare_store_users')->where('user_id', $id)->exists()) {
                    $operator->roles()->detach($role->id);
                }
                if (in_array($id, $oldOperators, true) !== in_array($id, $ids, true)) {
                    $operator->increment('session_version');
                }
            }
            $this->audit->record($request, $existing ? 'welfare.store_updated' : 'welfare.store_created', 'welfare_store', $store->id, old: $old, new: [...$store->only(['name', 'status']), 'operator_ids' => $ids]);

            return $store;
        }, 3);
    }

    public function setStoreStatus(Request $request, WelfareStore $existing, string $status, string $reason): void
    {
        DB::transaction(function () use ($request, $existing, $status, $reason): void {
            $this->lock();
            $actor = $this->actor($request, 'welfare.manage', true);
            $store = WelfareStore::whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $old = $store->status;
            if ($status === 'APPROVED' && ! $store->operators()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['status' => 'กำหนดผู้ปฏิบัติงานที่เปิดใช้งานก่อนอนุมัติร้าน']);
            }
            $store->update(['status' => $status, 'approved_by' => $status === 'APPROVED' ? $actor->id : $store->approved_by, 'approved_at' => $status === 'APPROVED' ? now() : $store->approved_at]);
            $this->audit->record($request, 'welfare.store_status', 'welfare_store', $store->id, old: ['status' => $old], new: ['status' => $status], reason: $reason);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveReward(Request $request, array $data, ?Reward $existing = null): Reward
    {
        return DB::transaction(function () use ($request, $data, $existing): Reward {
            $this->lock();
            $this->actor($request, 'welfare.manage', true);
            $reward = $existing ? Reward::whereKey($existing->id)->lockForUpdate()->firstOrFail() : new Reward;
            if ($existing && (int) $reward->welfare_store_id !== (int) $data['welfare_store_id']) {
                throw ValidationException::withMessages(['welfare_store_id' => 'ร้านของรายการเดิมเปลี่ยนไม่ได้ กรุณาปิดรายการแล้วสร้างรายการใหม่']);
            }
            WelfareStore::whereKey($data['welfare_store_id'])->firstOrFail();
            $old = $existing ? $reward->only(['name', 'required_points', 'discount_satang', 'is_active']) : null;
            $reward->fill(collect($data)->except('inventory')->all());
            $reward->valid_days = 90;
            if (! $existing) {
                $reward->inventory = (int) ($data['inventory'] ?? 0);
            }
            $reward->save();
            $this->audit->record($request, 'welfare.reward_saved', 'reward', $reward->id, old: $old, new: $reward->only(['name', 'required_points', 'discount_satang', 'is_active', 'inventory']));

            return $reward;
        }, 3);
    }

    public function addStock(Request $request, Reward $existing, int $quantity): void
    {
        DB::transaction(function () use ($request, $existing, $quantity): void {
            $this->lock();
            $this->actor($request, 'welfare.manage', true);
            $reward = Reward::whereKey($existing->id)->lockForUpdate()->firstOrFail();
            if ($reward->inventory + $quantity > 1000000) {
                throw ValidationException::withMessages(['quantity' => 'จำนวนคูปองคงเหลือต้องไม่เกิน 1,000,000 ใบ']);
            }
            $reward->increment('inventory', $quantity);
            $this->audit->record($request, 'welfare.stock_added', 'reward', $reward->id, new: ['quantity' => $quantity, 'inventory' => $reward->inventory]);
        }, 3);
    }

    public function issue(Request $request, Reward $offer, string $key, int $expectedPoints, int $expectedDiscount): RewardRedemption
    {
        return DB::transaction(function () use ($request, $offer, $key, $expectedPoints, $expectedDiscount): RewardRedemption {
            $this->lock();
            $holder = $this->actor($request, lock: true);
            $existing = RewardRedemption::where('user_id', $holder->id)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->reward_id === $offer->id && $existing->points_used === $expectedPoints && $existing->discount_satang === $expectedDiscount, 409);

                return $existing;
            }
            $reward = Reward::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $store = WelfareStore::whereKey($reward->welfare_store_id)->lockForUpdate()->firstOrFail();
            if (! $reward->is_active || $store->status !== 'APPROVED' || $reward->inventory < 1 || ($reward->start_date && $reward->start_date->gt(today())) || ($reward->end_date && $reward->end_date->lt(today()))) {
                throw ValidationException::withMessages(['reward' => 'รายการนี้ยังไม่เปิดแลก ร้านยังไม่อนุมัติ หรือคูปองหมดแล้ว']);
            }
            if ($reward->required_points !== $expectedPoints || $reward->discount_satang !== $expectedDiscount) {
                throw ValidationException::withMessages(['reward' => 'แต้ม/ส่วนลดเปลี่ยนแล้ว กรุณาโหลดรายการล่าสุดก่อนยืนยัน']);
            }
            if ($this->points->balance($holder) < $reward->required_points) {
                throw ValidationException::withMessages(['reward' => 'แต้มคงเหลือไม่พอสำหรับคูปองนี้']);
            }
            $code = 'CPN1-'.strtoupper(bin2hex(random_bytes(16)));
            $coupon = RewardRedemption::create(['coupon_id' => (string) Str::uuid(), 'request_key' => $key, 'coupon_code' => $code, 'coupon_hash' => hash('sha256', $code), 'user_id' => $holder->id, 'reward_id' => $reward->id, 'welfare_store_id' => $store->id, 'points_used' => $reward->required_points, 'discount_satang' => $reward->discount_satang, 'reward_name' => $reward->name, 'store_name' => $store->name, 'status' => 'APPROVED', 'requested_at' => now(), 'approved_at' => now(), 'expires_at' => now()->addDays(90)]);
            $entry = PointTransaction::create(['user_id' => $holder->id, 'transaction_type' => 'REDEEM_REWARD', 'reference_type' => 'reward_redemption', 'reference_id' => $coupon->id, 'idempotency_key' => 'coupon:'.$coupon->coupon_id, 'points' => -$reward->required_points, 'description' => 'แลกคูปอง '.$reward->name.' · '.$store->name, 'created_by' => $holder->id, 'created_at' => now()]);
            $coupon->update(['point_transaction_id' => $entry->id]);
            $reward->decrement('inventory');
            $this->audit->record($request, 'welfare.coupon_issued', 'reward_redemption', $coupon->id, new: ['holder_id' => $holder->id, 'reward_id' => $reward->id, 'store_id' => $store->id, 'points' => $coupon->points_used, 'discount_satang' => $coupon->discount_satang]);

            return $coupon;
        }, 3);
    }

    private function canonicalCode(string $code): string
    {
        $code = strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
        if (! preg_match('/\ACPN1-[A-F0-9]{32}\z/', $code)) {
            throw ValidationException::withMessages(['coupon_code' => 'ไม่พบคูปองที่ใช้ได้กับร้านนี้']);
        }

        return $code;
    }

    private function usable(RewardRedemption $coupon): void
    {
        if ($coupon->status === 'RECEIVED') {
            throw ValidationException::withMessages(['coupon_code' => 'คูปองนี้ใช้ไปแล้ว ใช้ซ้ำไม่ได้']);
        }
        if ($coupon->status !== 'APPROVED' || ! $coupon->expires_at || $coupon->expires_at->lte(now())) {
            throw ValidationException::withMessages(['coupon_code' => 'คูปองหมดอายุหรือไม่อยู่ในสถานะที่ใช้ได้']);
        }
        if (! $coupon->holder?->is_active) {
            throw ValidationException::withMessages(['coupon_code' => 'บัญชีผู้ถือคูปองไม่เปิดใช้งาน กรุณาติดต่อผู้ดูแล']);
        }
    }

    public function preview(Request $request, int $storeId, string $code): RewardRedemption
    {
        $actor = $this->actor($request, 'welfare.receive');
        $store = $this->storeFor($actor, $storeId, 'welfare.receive');
        if ($store->status !== 'APPROVED') {
            throw ValidationException::withMessages(['coupon_code' => 'ร้านนี้ยังไม่ได้รับอนุมัติหรือถูกระงับ']);
        }
        $coupon = RewardRedemption::with('holder')->where('welfare_store_id', $storeId)->where('coupon_hash', hash('sha256', $this->canonicalCode($code)))->first();
        if (! $coupon) {
            throw ValidationException::withMessages(['coupon_code' => 'ไม่พบคูปองที่ใช้ได้กับร้านนี้']);
        }
        $this->usable($coupon);

        return $coupon;
    }

    public function receive(Request $request, int $storeId, string $code, string $couponId, string $key): RewardRedemption
    {
        return DB::transaction(function () use ($request, $storeId, $code, $couponId, $key): RewardRedemption {
            $this->lock();
            $actor = $this->actor($request, 'welfare.receive', true);
            $store = $this->storeFor($actor, $storeId, 'welfare.receive', true);
            if ($store->status !== 'APPROVED') {
                throw ValidationException::withMessages(['coupon_code' => 'ร้านนี้ยังไม่ได้รับอนุมัติหรือถูกระงับ']);
            }
            $coupon = RewardRedemption::with('holder')->where('welfare_store_id', $storeId)->where('coupon_hash', hash('sha256', $this->canonicalCode($code)))->lockForUpdate()->first();
            if (! $coupon || $coupon->coupon_id !== $couponId) {
                throw ValidationException::withMessages(['coupon_code' => 'ข้อมูลไม่ตรงกับคูปองที่ตรวจ กรุณาตรวจใหม่']);
            }
            if ($coupon->status === 'RECEIVED' && $coupon->receive_request_key === $key && $coupon->received_by === $actor->id) {
                return $coupon;
            }
            $this->usable($coupon);
            $coupon->update(['status' => 'RECEIVED', 'received_at' => now(), 'received_by' => $actor->id, 'receive_request_key' => $key]);
            $this->audit->record($request, 'welfare.coupon_received', 'reward_redemption', $coupon->id, new: ['store_id' => $store->id, 'discount_satang' => $coupon->discount_satang]);

            return $coupon;
        }, 3);
    }

    /** @param list<string> $couponIds */
    public function claim(Request $request, int $storeId, array $couponIds, string $start, string $end, string $key, ?string $note): WelfareClaim
    {
        sort($couponIds);
        $fingerprint = hash('sha256', json_encode([$couponIds, $start, $end, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $storeId, $couponIds, $start, $end, $key, $note, $fingerprint): WelfareClaim {
            $this->lock();
            $actor = $this->actor($request, 'welfare.claims', true);
            $store = $this->storeFor($actor, $storeId, 'welfare.claims', true);
            $existing = WelfareClaim::where('welfare_store_id', $storeId)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->selection_hash === $fingerprint && $existing->created_by === $actor->id, 409);

                return $existing;
            }
            $rows = RewardRedemption::with('holder')->where('welfare_store_id', $storeId)->whereIn('coupon_id', $couponIds)->where('status', 'RECEIVED')->where('received_at', '>=', Carbon::parse($start)->startOfDay())->where('received_at', '<', Carbon::parse($end)->addDay()->startOfDay())->whereDoesntHave('claimItem')->orderBy('id')->lockForUpdate()->get();
            if ($rows->count() !== count($couponIds)) {
                throw ValidationException::withMessages(['coupon_ids' => 'บางรายการไม่อยู่ในร้าน/ช่วงวันที่นี้ ยังไม่ใช้ หรืออยู่ในใบส่งเบิกแล้ว กรุณาโหลดใหม่']);
            }
            $claim = WelfareClaim::create(['claim_id' => (string) Str::uuid(), 'request_key' => $key, 'selection_hash' => $fingerprint, 'welfare_store_id' => $store->id, 'start_date' => $start, 'end_date' => $end, 'organization_name' => app(FoundationSettings::class)->organizationName(), 'store_name' => $store->name, 'coupon_count' => $rows->count(), 'total_satang' => $rows->sum('discount_satang'), 'note' => $note, 'created_by' => $actor->id, 'created_at' => now()]);
            foreach ($rows as $coupon) {
                $claim->items()->create(['reward_redemption_id' => $coupon->id, 'coupon_id' => $coupon->coupon_id, 'reward_name' => $coupon->reward_name, 'holder_name' => $coupon->holder->display_name, 'discount_satang' => $coupon->discount_satang, 'received_at' => $coupon->received_at]);
            }
            $this->audit->record($request, 'welfare.claim_created', 'welfare_claim', $claim->id, new: ['store_id' => $store->id, 'coupon_count' => $claim->coupon_count, 'total_satang' => $claim->total_satang]);

            return $claim;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function couponData(RewardRedemption $coupon): array
    {
        return ['coupon_id' => $coupon->coupon_id, 'reward_name' => $coupon->reward_name, 'store_name' => $coupon->store_name, 'discount_satang' => $coupon->discount_satang, 'points_used' => $coupon->points_used, 'status' => $coupon->status === 'APPROVED' && $coupon->expires_at?->lte(now()) ? 'EXPIRED' : $coupon->status, 'requested_at' => $coupon->requested_at->toIso8601String(), 'expires_at' => $coupon->expires_at?->toIso8601String(), 'received_at' => $coupon->received_at?->toIso8601String()];
    }
}
