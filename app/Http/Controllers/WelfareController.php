<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Models\WelfareClaim;
use App\Models\WelfareStore;
use App\Services\PointService;
use App\Services\WelfareClaimFileWriter;
use App\Services\WelfareService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class WelfareController extends Controller
{
    public function catalog(Request $request, WelfareService $service, PointService $points): Response
    {
        $actor = $service->actor($request);
        $offers = Reward::with('store')->where('is_active', true)->whereHas('store', fn ($query) => $query->where('status', 'APPROVED'))->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<=', today()))->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', today()))->orderBy('required_points')->get()->map(fn (Reward $reward): array => ['id' => $reward->id, 'name' => $reward->name, 'description' => $reward->description, 'store_name' => $reward->store->name, 'required_points' => $reward->required_points, 'discount_satang' => $reward->discount_satang, 'inventory' => $reward->inventory, 'valid_days' => $reward->valid_days]);

        return Inertia::render('Welfare/Catalog', ['offers' => $offers, 'balance' => $points->balance($actor), 'coupons' => RewardRedemption::where('user_id', $actor->id)->whereNotNull('coupon_id')->latest('id')->paginate(20)->through(fn (RewardRedemption $coupon): array => $service->couponData($coupon))]);
    }

    public function issue(Request $request, Reward $reward, WelfareService $service): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'expected_points' => ['required', 'integer', 'min:1'], 'expected_discount_satang' => ['required', 'integer', 'min:1']]);
        $coupon = $service->issue($request, $reward, $data['request_key'], (int) $data['expected_points'], (int) $data['expected_discount_satang']);

        return redirect('/coupons/'.$coupon->coupon_id)->with('message', 'แลกแต้มและออกคูปองแล้ว ใช้ได้เฉพาะร้านที่ระบุภายใน 90 วัน');
    }

    public function card(Request $request, RewardRedemption $coupon, WelfareService $service): Response
    {
        $actor = $service->actor($request);
        abort_unless($coupon->user_id === $actor->id, 403);
        $data = $service->couponData($coupon);
        $storeStatus = WelfareStore::whereKey($coupon->welfare_store_id)->value('status');
        $canUse = $data['status'] === 'APPROVED' && $storeStatus === 'APPROVED';

        return Inertia::render('Welfare/Card', ['coupon' => [...$data, 'holder_name' => $actor->display_name, 'store_status' => $storeStatus, 'coupon_code' => $canUse ? $coupon->coupon_code : null]]);
    }

    public function manage(): Response
    {
        Gate::authorize('welfare.manage');

        return Inertia::render('Welfare/Manage', ['stores' => WelfareStore::with('operators:id,display_name')->orderBy('id')->get()->map(fn (WelfareStore $store): array => [...$store->only(['id', 'code', 'name', 'contact', 'status']), 'operators' => $store->operators->map(fn (User $user): array => $user->only(['id', 'display_name']))]), 'people' => User::where('is_active', true)->orderBy('display_name')->get(['id', 'display_name', 'employee_code']), 'offers' => Reward::with('store:id,name')->orderBy('id')->get(), 'claims' => WelfareClaim::latest('id')->paginate(20)]);
    }

    /** @return array<string, mixed> */
    private function storeData(Request $request, ?WelfareStore $store = null): array
    {
        return $request->validate(['code' => [$store ? 'sometimes' : 'required', 'string', 'max:40', 'regex:/\A[A-Za-z0-9_-]+\z/', Rule::unique('welfare_stores', 'code')->ignore($store?->id)], 'name' => ['required', 'string', 'max:200'], 'contact' => ['nullable', 'string', 'max:250'], 'operator_ids' => ['required', 'array', 'min:1', 'max:50'], 'operator_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)]]);
    }

    public function createStore(Request $request, WelfareService $service): RedirectResponse
    {
        Gate::authorize('welfare.manage');
        $service->saveStore($request, $this->storeData($request));

        return back()->with('message', 'สร้างร้านแล้ว รอ Admin อนุมัติก่อนเปิดรับคูปอง');
    }

    public function updateStore(Request $request, WelfareStore $store, WelfareService $service): RedirectResponse
    {
        Gate::authorize('welfare.manage');
        $service->saveStore($request, $this->storeData($request, $store), $store);

        return back()->with('message', 'บันทึกร้านและผู้ปฏิบัติงานแล้ว ผู้ที่เปลี่ยนสิทธิ์ต้องเข้าสู่ระบบใหม่');
    }

    public function storeStatus(Request $request, WelfareStore $store, WelfareService $service): RedirectResponse
    {
        Gate::authorize('welfare.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['APPROVED', 'SUSPENDED'])], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->setStoreStatus($request, $store, $data['status'], $data['reason']);

        return back()->with('message', 'บันทึกสถานะร้านแล้ว');
    }

    /** @return array<string, mixed> */
    private function rewardData(Request $request, bool $creating): array
    {
        $rules = ['welfare_store_id' => ['required', 'integer', Rule::exists('welfare_stores', 'id')], 'name' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'required_points' => ['required', 'integer', 'between:1,1000000'], 'discount_satang' => ['required', 'integer', 'between:1,10000000'], 'is_active' => ['required', 'boolean'], 'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']];
        if ($creating) {
            $rules['inventory'] = ['required', 'integer', 'between:0,1000000'];
        }

        return $request->validate($rules);
    }

    public function createReward(Request $request, WelfareService $service): RedirectResponse
    {
        Gate::authorize('welfare.manage');
        $service->saveReward($request, $this->rewardData($request, true));

        return back()->with('message', 'สร้างรายการคูปองแล้ว อายุ 90 วันนับจากวันที่แลก');
    }

    public function updateReward(Request $request, Reward $reward, WelfareService $service): RedirectResponse
    {
        Gate::authorize('welfare.manage');
        $service->saveReward($request, $this->rewardData($request, false), $reward);

        return back()->with('message', 'บันทึกรายการแล้ว คูปองที่ออกไปก่อนหน้านี้ใช้รายละเอียดเดิม');
    }

    public function stock(Request $request, Reward $reward, WelfareService $service): RedirectResponse
    {
        Gate::authorize('welfare.manage');
        $data = $request->validate(['quantity' => ['required', 'integer', 'between:1,1000000']]);
        $service->addStock($request, $reward, (int) $data['quantity']);

        return back()->with('message', 'เพิ่มจำนวนคูปองที่เปิดแลกแล้ว');
    }

    /** @return array{start_date:string,end_date:string} */
    private function period(Request $request): array
    {
        $data = $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d']]);
        $start = $data['start_date'] ?? now()->startOfMonth()->format('Y-m-d');
        $end = $data['end_date'] ?? today()->format('Y-m-d');
        if ($start > $end || Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 366) {
            throw ValidationException::withMessages(['start_date' => 'เลือกช่วงวันที่เรียงลำดับและไม่เกิน 366 วัน']);
        }

        return ['start_date' => $start, 'end_date' => $end];
    }

    public function merchant(Request $request, WelfareService $service): Response
    {
        $actor = $service->actor($request, 'welfare.reports');
        $stores = WelfareStore::whereHas('operators', fn ($query) => $query->where('users.id', $actor->id))->orderBy('id')->get(['id', 'name', 'status']);
        $id = $request->validate(['store_id' => ['nullable', 'integer']])['store_id'] ?? $stores->first()?->id;
        $period = $this->period($request);
        if ($id === null) {
            return Inertia::render('Welfare/Merchant', ['stores' => $stores, 'store' => null, 'period' => $period, 'coupons' => null, 'claims' => null, 'summary' => null]);
        }
        $store = $service->storeFor($actor, (int) $id, 'welfare.reports');
        $query = RewardRedemption::where('welfare_store_id', $store->id)->where('status', 'RECEIVED')->where('received_at', '>=', Carbon::parse($period['start_date'])->startOfDay())->where('received_at', '<', Carbon::parse($period['end_date'])->addDay()->startOfDay());
        $unclaimed = (clone $query)->whereDoesntHave('claimItem');
        $summary = ['count' => (clone $query)->count(), 'total_satang' => (int) (clone $query)->sum('discount_satang'), 'unclaimed_count' => (clone $unclaimed)->count(), 'unclaimed_satang' => (int) (clone $unclaimed)->sum('discount_satang')];

        return Inertia::render('Welfare/Merchant', ['stores' => $stores, 'store' => $store->only(['id', 'name', 'status']), 'period' => $period, 'summary' => $summary, 'coupons' => $query->with(['holder:id,display_name', 'receiver:id,display_name', 'claimItem.claim'])->latest('received_at')->latest('id')->paginate(25)->withQueryString()->through(fn (RewardRedemption $coupon): array => [...$service->couponData($coupon), 'holder_name' => $coupon->holder->display_name, 'receiver_name' => $coupon->receiver?->display_name, 'claim_id' => $coupon->claimItem?->claim?->claim_id]), 'claims' => WelfareClaim::where('welfare_store_id', $store->id)->latest('id')->paginate(10, ['*'], 'claims_page')->withQueryString()]);
    }

    public function preview(Request $request, WelfareService $service): JsonResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer'], 'coupon_code' => ['required', 'string', 'max:100']]);
        $coupon = $service->preview($request, (int) $data['store_id'], $data['coupon_code']);

        return response()->json(['coupon' => [...$service->couponData($coupon), 'holder_name' => $coupon->holder->display_name]]);
    }

    public function receive(Request $request, WelfareService $service): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer'], 'coupon_code' => ['required', 'string', 'max:100'], 'coupon_id' => ['required', 'uuid'], 'request_key' => ['required', 'uuid']]);
        $service->receive($request, (int) $data['store_id'], $data['coupon_code'], $data['coupon_id'], $data['request_key']);

        return redirect('/welfare/store?store_id='.$data['store_id'])->with('message', 'รับคูปองแล้ว ใช้ได้ครั้งเดียว รายการนี้พร้อมเลือกส่งเบิก');
    }

    public function createClaim(Request $request, WelfareService $service): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer'], 'coupon_ids' => ['required', 'array', 'min:1', 'max:500'], 'coupon_ids.*' => ['required', 'uuid', 'distinct'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'request_key' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $this->period($request);
        $claim = $service->claim($request, (int) $data['store_id'], $data['coupon_ids'], $data['start_date'], $data['end_date'], $data['request_key'], $data['note'] ?? null);

        return redirect('/welfare/claims/'.$claim->claim_id)->with('message', 'จัดทำใบสรุปส่งเบิกแล้ว รายการที่เลือกจะไม่ถูกส่งเบิกซ้ำ');
    }

    private function authorizeClaim(Request $request, WelfareClaim $claim, WelfareService $service): void
    {
        $actor = $service->actor($request);
        if (! $actor->hasPermission('welfare.manage')) {
            $service->storeFor($actor, (int) $claim->welfare_store_id, 'welfare.claims');
        }
    }

    public function showClaim(Request $request, WelfareClaim $claim, WelfareService $service): Response
    {
        $this->authorizeClaim($request, $claim, $service);

        return Inertia::render('Welfare/Claim', ['claim' => $claim->load('items'), 'creator' => User::whereKey($claim->created_by)->value('display_name')]);
    }

    public function claimFile(Request $request, WelfareClaim $claim, string $format, WelfareService $service, WelfareClaimFileWriter $writer): HttpResponse
    {
        $this->authorizeClaim($request, $claim, $service);

        return $writer->download($claim->load('items'), $format);
    }
}
