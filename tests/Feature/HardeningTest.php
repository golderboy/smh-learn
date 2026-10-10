<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HardeningTest extends FoundationTestCase
{
    public function test_private_pages_have_security_headers_and_no_shared_cache(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
        $response = $this->actingAs($this->userWithRole('learner'))->get('/dashboard')->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('unsafe-eval', (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_http_metrics_exclude_callback_query_body_and_credentials_including_errors(): void
    {
        $logger = \Mockery::mock();
        Log::shouldReceive('channel')->with('http_metrics')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('http', \Mockery::on(function (array $metrics): bool {
            $this->assertSame('unmatched', $metrics['route']);
            $this->assertSame(500, $metrics['status']);
            $this->assertStringNotContainsString('private', json_encode($metrics));

            return true;
        }));
        (new RecordHttpMetrics)->terminate(Request::create('/auth/thaid/callback?code=private-code&pid=private-pid', 'GET'), new Response('', 500));
    }

    public function test_monitor_returns_nonzero_for_stale_scheduler_and_missing_backup(): void
    {
        Cache::store('redis')->forget('ops:scheduler-heartbeat');
        $this->artisan('ops:check', ['--json' => true])->assertExitCode(1);
        Cache::store('redis')->put('ops:scheduler-heartbeat', time(), 180);
        config(['operations.backup_max_age_hours' => -1]);
        $this->artisan('ops:check', ['--backup' => true, '--json' => true])->assertExitCode(1);
        config(['app.url' => 'http://localhost']);
        $this->artisan('ops:check', ['--tls' => true, '--json' => true])->assertExitCode(1);
    }

    public function test_release_guard_refuses_dev_and_demo_and_snapshot_refuses_test_database(): void
    {
        $this->userWithRole('administrator')->update(['is_demo' => true]);
        $this->artisan('ops:release-check', ['--json' => true])->assertExitCode(1);
        $this->artisan('ops:snapshot')->assertExitCode(1);
        $this->artisan('ops:backup-inventory')->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }
}
