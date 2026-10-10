<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['thaidAvailable' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/\A(?:[0-9]{1,15}|admins)\z/'],
            'password' => ['required', 'string', 'max:255'],
        ], ['username.regex' => 'ใช้เลขประจำตัว 1–15 หลัก หรือบัญชี admins สำหรับผู้ดูแลระบบตั้งต้น']);
        $key = 'login:'.hash('sha256', $data['username'].'|'.$request->ip());
        $ipKey = 'login-ip:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            throw ValidationException::withMessages(['username' => 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอสักครู่แล้วลองใหม่']);
        }
        if (! Auth::attempt(['username' => $data['username'], 'password' => $data['password'], 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($ipKey, 60);
            $this->audit->record($request, 'auth.failed', 'authentication');
            throw ValidationException::withMessages(['username' => 'เลขประจำตัวหรือรหัสผ่านไม่ถูกต้อง หรือบัญชียังไม่เปิดใช้งาน']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        DB::transaction(function () use ($user, $request): void {
            $user->update(['last_login_at' => now()]);
            $this->audit->record($request, 'auth.login', 'user', $user->id);
        });
        $request->session()->put('auth_version', $user->session_version);
        $request->session()->put('auth_last_activity', time());

        return redirect()->route($user->must_change_password && config('identity.require_initial_password_change') ? 'password.edit' : 'dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->audit->record($request, 'auth.logout', 'user', $user instanceof User ? $user->id : null);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function editPassword(): Response
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed', 'different:current_password'],
        ], ['current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง', 'password.min' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 10 ตัวอักษร', 'password.confirmed' => 'ยืนยันรหัสผ่านไม่ตรงกัน', 'password.different' => 'กรุณาใช้รหัสผ่านใหม่ที่ต่างจากเดิม']);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        DB::transaction(function () use ($user, $data, $request): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            // Recheck after acquiring the lock to reject a concurrent administrative reset.
            if (! Hash::check($data['current_password'], $locked->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'รหัสผ่านเปลี่ยนแปลงแล้ว กรุณาเข้าสู่ระบบใหม่']);
            }
            $locked->password_hash = $data['password'];
            $locked->must_change_password = false;
            $locked->session_version++;
            $locked->remember_token = null;
            $locked->save();
            $request->session()->put('auth_version', $locked->session_version);
            $this->audit->record($request, 'user.password_changed', 'user', $locked->id);
        });
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('message', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
    }
}
