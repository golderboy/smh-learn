<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\FoundationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureSessionLifetime
{
    public function __construct(private FoundationSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Set the cookie and Redis handler lifetime before StartSession resolves its driver.
        config(['session.lifetime' => $this->settings->sessionMinutes()]);

        return $next($request);
    }
}
