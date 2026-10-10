<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PointService;
use App\Services\ReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, ReportingService $reports, PointService $points): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $isAdmin = $user->hasPermission('users.manage');
        $mine = $reports->individuals($user, ['audience' => 'mine']);
        $professional = ! $reports->scope($user)['personal'];

        return Inertia::render('Dashboard', [
            'personalSummary' => $reports->summary($mine),
            'reportSummary' => $professional ? $reports->summary($reports->individuals($user)) : null,
            'pointBalance' => $points->balance($user),
            'recentResults' => $mine->sortByDesc('enrollment_id')->take(5)->values(),
            'counts' => $isAdmin ? ['users' => User::query()->count(), 'activeUsers' => User::query()->where('is_active', true)->count(), 'departments' => Department::query()->count(), 'positions' => Position::query()->count(), 'categories' => CourseCategory::query()->count()] : null,
            'supervisedCategories' => $user->hasPermission('categories.view') ? $user->supervisedCategories()->get(['course_categories.id', 'code', 'name', 'is_active']) : [],
            'recentAudit' => $user->hasPermission('audit.view') ? AuditLog::query()->with('user:id,display_name')->latest('id')->limit(5)->get(['id', 'user_id', 'action', 'created_at']) : [],
            'enrollments' => CourseEnrollment::where('user_id', $user->id)->with('session.version.course')->latest('id')->limit(5)->get(),
            'learningCounts' => [
                'notStarted' => CourseEnrollment::where('user_id', $user->id)->whereIn('state', ['ASSIGNED', 'NOT_STARTED'])->count(),
                'inProgress' => CourseEnrollment::where('user_id', $user->id)->whereIn('state', ['LEARNING', 'PRETEST_COMPLETED', 'CONTENT_COMPLETED'])->count(),
                'completed' => CourseEnrollment::where('user_id', $user->id)->where('state', 'COMPLETED')->count(),
                'overdue' => CourseEnrollment::where('user_id', $user->id)->whereNull('completed_at')->whereNull('cancelled_at')->whereDate('due_date', '<', now()->toDateString())->count(),
            ],
        ]);
    }

    public function profile(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Profile', ['profile' => $user->only(['username', 'employee_code', 'first_name', 'last_name', 'email', 'phone', 'employment_status', 'is_root_admin'])]);
    }

    public function updateProfile(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ! $user->isRootAdministrator(), 403);
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'phone' => ['nullable', 'string', 'max:30']]);
        DB::transaction(function () use ($request, $user, $data, $audit): void {
            $old = $user->only(['email', 'phone']);
            $user->update($data);
            $audit->record($request, 'user.profile_updated', 'user', $user->id, $old, $data);
        });

        return back()->with('message', 'บันทึกข้อมูลส่วนตัวแล้ว');
    }

    public function department(Request $request): Response
    {
        Gate::authorize('department.view');
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $canSelectDepartments = $user->hasRole('administrator') && $user->hasPermission('users.manage');
        $departmentId = $canSelectDepartments
            ? ($request->validate(['department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')]])['department_id'] ?? null)
            : $user->department_id;
        $departmentId = $departmentId === null ? null : (int) $departmentId;
        $staff = User::query();
        if ($departmentId !== null) {
            $staff->where('department_id', $departmentId);
        } elseif (! $canSelectDepartments) {
            $staff->whereRaw('1 = 0');
        }

        return Inertia::render('Department', [
            'departmentName' => $departmentId === null ? ($canSelectDepartments ? 'ทุกแผนก' : 'ยังไม่ได้กำหนดแผนก') : Department::whereKey($departmentId)->value('name'),
            'canSelectDepartments' => $canSelectDepartments,
            'departments' => $canSelectDepartments ? Department::orderBy('name')->get(['id', 'name']) : [],
            'filters' => ['department_id' => $departmentId],
            'staff' => $staff->with(['position:id,name', 'department:id,name'])->orderBy('display_name')->paginate(20, ['id', 'display_name', 'department_id', 'position_id', 'is_active'])->withQueryString(),
        ]);
    }
}
