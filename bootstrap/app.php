<?php

use App\Http\Middleware\ConfigureSessionLifetime;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordHttpMetrics;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use App\Services\FoundationSettings;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RecordHttpMetrics::class);
        $middleware->prependToGroup('web', ConfigureSessionLifetime::class);
        $middleware->prependToPriorityList(StartSession::class, ConfigureSessionLifetime::class);
        $middleware->web(append: [SecurityHeaders::class, HandleInertiaRequests::class]);
        $middleware->alias(['active.session' => EnsureActiveSession::class, 'password.changed' => RequirePasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['coupon_code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $status = $response->getStatusCode();
            if ($request->expectsJson() || $request->is('api/*') || ! in_array($status, [403, 404, 409, 419, 429, 500, 503], true)) {
                return $response;
            }

            // Unmatched routes do not run the web middleware that shares public branding.
            $props = ['status' => $status];
            try {
                $settings = app(FoundationSettings::class);
                $branding = $settings->brandingText();
                $props['app'] = ['name' => $branding['product_name'], 'organization' => $settings->organizationName(), 'timezone' => config('app.timezone'), 'branding' => [...$branding, ...$settings->assetUrls()]];
            } catch (Throwable) {
                // An unavailable database must not prevent rendering the original error.
                $props['app'] = ['name' => config('identity.product_name')];
            }
            $error = Inertia::render('Error', $props)->toResponse($request)->setStatusCode($status);
            $error->headers->set('Cache-Control', 'private, no-store');
            foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'Retry-After'] as $header) {
                if ($response->headers->has($header)) {
                    $error->headers->set($header, (string) $response->headers->get($header));
                }
            }

            return $error;
        });
    })->create();
