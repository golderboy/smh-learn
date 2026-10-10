<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\FoundationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('users.manage');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'department_id' => ['nullable', 'integer', 'exists:departments,id']]);
        $search = $filters['search'] ?? '';
        $query = User::query()->with(['department:id,name', 'position:id,name', 'roles:id,name,code']);
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('display_name', 'like', '%'.$search.'%')->orWhere('username', $search)->orWhere('employee_code', 'like', '%'.$search.'%'));
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        return Inertia::render('Admin/Users/Index', ['users' => $query->orderBy('id')->paginate(15)->withQueryString(), 'filters' => $filters, 'departments' => Department::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function create(): Response
    {
        Gate::authorize('users.manage');

        return $this->form();
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return $this->form($user);
    }

    private function form(?User $user = null): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'record' => $user?->load('roles:id,name,code'),
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'positions' => Position::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()->orderBy('id')->get(['id', 'name', 'code']),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('users')->ignore($user?->id)],
            'username' => ['required', 'string', 'regex:/\A[0-9]{1,15}\z/', Rule::unique('users')->ignore($user?->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('is_active', true)],
            'employment_status' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => ['required', 'integer', 'distinct', 'exists:roles,id'],
        ], ['username.regex' => 'เลขประจำตัวต้องเป็นตัวเลข 1–15 หลัก', 'username.unique' => 'มีเลขประจำตัวนี้ในระบบแล้ว', 'employee_code.unique' => 'มีรหัสบุคลากรนี้ในระบบแล้ว']);
    }

    public function store(Request $request, FoundationService $service): RedirectResponse
    {
        Gate::authorize('users.manage');
        $service->saveUser($request, $this->validated($request));

        return redirect()->route('admin.users.index')->with('message', 'เพิ่มบัญชีบุคลากรแล้ว');
    }

    public function update(Request $request, User $user, FoundationService $service): RedirectResponse
    {
        Gate::authorize('update', $user);
        $service->saveUser($request, $this->validated($request, $user), $user);

        return redirect()->route('admin.users.index')->with('message', 'บันทึกบัญชีบุคลากรแล้ว');
    }

    public function resetPassword(Request $request, User $user, FoundationService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $service->resetPassword($request, $user, $data['reason']);

        return back()->with('message', 'รีเซ็ตรหัสผ่านเริ่มต้นแล้ว ผู้ใช้ต้องเข้าสู่ระบบใหม่');
    }
}
