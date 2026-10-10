<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', $request->is('login', 'auth/thaid*') ? 'no-referrer' : 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
        // External media varies by organization. Roll out resource restrictions in report-only mode.
        $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://www.youtube.com https://s.ytimg.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; media-src 'self' blob: https:; frame-src https://www.youtube.com https://www.youtube-nocookie.com; worker-src 'self' blob:; connect-src 'self' https://www.youtube.com https://*.youtube.com");
        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->user() !== null || $request->is('login', 'auth/thaid*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
