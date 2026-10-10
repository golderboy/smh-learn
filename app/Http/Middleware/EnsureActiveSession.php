<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\FoundationSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    public function __construct(private FoundationSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }
        // Read current account state on every request, including requests in a long-lived worker.
        $currentUser = User::query()->find($user->id);
        $lastActivity = (int) $request->session()->get('auth_last_activity', time());
        $version = (int) $request->session()->get('auth_version', $currentUser?->session_version);
        if (! $currentUser || ! $currentUser->is_active || $version !== $currentUser->session_version || time() - $lastActivity > $this->settings->sessionMinutes() * 60) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'กรุณาเข้าสู่ระบบใหม่'], 401) : redirect()->route('login')->with('message', 'สิทธิ์หรือเซสชันเปลี่ยนแปลง กรุณาเข้าสู่ระบบใหม่');
        }
        Auth::setUser($currentUser);
        $request->session()->put('auth_version', $version);
        $request->session()->put('auth_last_activity', time());

        return $next($request);
    }
}
