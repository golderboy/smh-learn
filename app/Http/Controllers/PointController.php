<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourseSession;
use App\Models\PointRule;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PointService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PointController extends Controller
{
    public function index(Request $request, PointService $points): Response
    {
        $user = $this->actor($request);

        return Inertia::render('Points/Index', ['balance' => $points->balance($user), 'history' => PointTransaction::where('user_id', $user->id)->latest('id')->paginate(25), 'canManage' => $user->hasPermission('points.manage')]);
    }

    public function manage(Request $request): Response
    {
        Gate::authorize('points.manage');
        $selected = $request->validate(['user_id' => ['nullable', 'integer', Rule::exists('users', 'id')]])['user_id'] ?? null;

        return Inertia::render('Points/Manage', ['rules' => PointRule::orderBy('id')->get(), 'people' => User::orderBy('display_name')->get(['id', 'display_name', 'employee_code']), 'selectedUserId' => $selected,
            'balances' => DB::table('point_transactions')->selectRaw('user_id, SUM(points) AS balance')->groupBy('user_id')->pluck('balance', 'user_id'),
            'history' => PointTransaction::query()->when($selected, fn ($query) => $query->where('user_id', $selected))->latest('id')->paginate(25)->withQueryString()]);
    }

    public function rules(Request $request, AuditService $audit): RedirectResponse
    {
        Gate::authorize('points.manage');
        $data = $request->validate(['rules' => ['required', 'array', 'size:3'], 'rules.*.code' => ['required', 'distinct', Rule::in(['COMPLETE_COURSE', 'PASS_POSTTEST', 'HIGH_SCORE'])], 'rules.*.points' => ['required', 'integer', 'min:0', 'max:1000000'], 'rules.*.is_active' => ['required', 'boolean'], 'rules.*.threshold_percent' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        /** @var list<array{code:string,points:int,is_active:bool,threshold_percent?:float|null}> $rows */
        $rows = $data['rules'];
        DB::transaction(function () use ($request, $audit, $rows): void {
            foreach (collect($rows)->sortBy('code') as $row) {
                if ($row['code'] === 'HIGH_SCORE' && ($row['threshold_percent'] ?? null) === null) {
                    throw ValidationException::withMessages(['rules' => 'ระบุเปอร์เซ็นต์ขั้นต่ำของโบนัสคะแนนสูง']);
                }
                $rule = PointRule::where('code', $row['code'])->lockForUpdate()->firstOrFail();
                $old = $rule->only(['points', 'is_active', 'threshold_percent']);
                $rule->update(['points' => $row['points'], 'is_active' => $row['is_active'], 'threshold_percent' => $row['code'] === 'HIGH_SCORE' ? $row['threshold_percent'] : null, 'updated_by' => $request->user()?->id]);
                $audit->record($request, 'points.rule_updated', 'point_rule', $rule->id, old: $old, new: $rule->only(['points', 'is_active', 'threshold_percent']));
            }
        });

        return back()->with('message', 'บันทึกกฎสำหรับรอบเรียนที่เปิดใช้แต้มใหม่แล้ว กฎของรอบที่ตรึงไว้ไม่เปลี่ยน');
    }

    public function session(Request $request, CourseSession $session, PointService $points, AuditService $audit): RedirectResponse
    {
        Gate::authorize('points.manage');
        $data = $request->validate(['points_enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $session, $data, $points, $audit): void {
            $locked = CourseSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (PointTransaction::whereIn('enrollment_id', $locked->enrollments()->select('id'))->exists()) {
                throw ValidationException::withMessages(['points' => 'รอบนี้มีประวัติให้แต้มแล้ว กฎถูกตรึงไว้ กรุณาสร้างรอบใหม่']);
            }
            $old = $locked->only(['points_enabled', 'point_rules_snapshot']);
            if ($data['points_enabled'] && $locked->points_activated_at === null) {
                // A timestamp alone cannot distinguish completions in the same second as activation.
                $locked->enrollments()->whereNotNull('completed_at')->update(['points_ineligible' => true]);
            }
            $locked->update(['points_enabled' => $data['points_enabled'], 'point_rules_snapshot' => $locked->point_rules_snapshot ?? ($data['points_enabled'] ? $points->rules() : null), 'points_activated_at' => $locked->points_activated_at ?? ($data['points_enabled'] ? now() : null)]);
            $audit->record($request, 'points.session_configured', 'course_session', $locked->id, old: $old, new: $locked->only(['points_enabled', 'point_rules_snapshot']));
        });

        return back()->with('message', 'บันทึกการให้แต้มของรอบเรียนแล้ว ไม่ให้แต้มย้อนหลังจากการตั้งค่านี้');
    }

    public function adjust(Request $request, User $user, PointService $points): RedirectResponse
    {
        Gate::authorize('points.manage');
        $request->merge(['reason' => trim((string) $request->input('reason'))]);
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'request_key' => ['required', 'uuid']]);
        $points->adjust($request, $user, (int) $data['points'], trim($data['reason']), $data['request_key']);

        return redirect('/admin/points?user_id='.$user->id)->with('message', 'บันทึกการปรับแต้มและเหตุผลแล้ว');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
