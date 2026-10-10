<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RecordHttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('ops.started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (config('operations.http_metrics')) {
            // No URLs/queries/usernames/cookies/callback codes in metrics.
            Log::channel('http_metrics')->info('http', ['method' => $request->method(),
                'route' => $request->route()?->uri() ?? 'unmatched', 'status' => $response->getStatusCode(),
                'duration_ms' => round((microtime(true) - (float) $request->attributes->get('ops.started_at', microtime(true))) * 1000, 2)]);
        }
    }
}
