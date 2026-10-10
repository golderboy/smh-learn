<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\InfrastructureProbe;
use App\Services\InfrastructureStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class CheckOperations extends Command
{
    protected $signature = 'ops:check {--queue : Verify a real worker round trip} {--backup : Require a recent verified backup} {--tls : Verify HTTPS certificate and at least 14 days validity} {--json}';

    protected $description = 'Private CLI health check; exits nonzero on a failed check';

    public function handle(InfrastructureStatus $status): int
    {
        $started = microtime(true);
        $checks = $status->check()['checks'];
        $free = disk_free_space(storage_path());
        $checks['disk'] = $free !== false && $free >= (int) config('operations.minimum_free_bytes');
        $failed = null;
        $queueDepth = null;
        try {
            $heartbeat = filter_var(Cache::store('redis')->get('ops:scheduler-heartbeat'), FILTER_VALIDATE_INT);
            $checks['scheduler'] = $heartbeat !== false && $heartbeat >= time() - 150 && $heartbeat <= time();
            $queueDepth = Queue::connection('redis')->size();
        } catch (\Throwable) {
            $checks['scheduler'] = false;
        }
        try {
            $failed = DB::table('failed_jobs')->count();
            $checks['failed_jobs'] = $failed === 0;
        } catch (\Throwable) {
            $checks['failed_jobs'] = false;
        }
        if ($this->option('queue')) {
            $checks['worker'] = $this->worker();
        }
        if ($this->option('tls')) {
            $checks['tls'] = $this->tls();
        }
        $backup = json_decode(@file_get_contents(storage_path('app/ops/last-backup.json')) ?: '{}', true);
        if ($this->option('backup')) {
            $checks['backup'] = is_array($backup) && ($backup['verified'] ?? false) === true
                && is_int($backup['created_at'] ?? null) && $backup['created_at'] <= time()
                && time() - $backup['created_at'] <= (int) config('operations.backup_max_age_hours') * 3600;
        }
        $result = ['ok' => ! in_array(false, $checks, true), 'checks' => $checks,
            'metrics' => ['free_disk_bytes' => $free, 'php_peak_memory_bytes' => memory_get_peak_usage(true),
                'load_average' => sys_getloadavg(), 'system_memory' => $this->memory(), 'queue_depth' => $queueDepth,
                'failed_jobs' => $failed, 'duration_ms' => round((microtime(true) - $started) * 1000, 2)]];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line($name.': '.($passed ? 'PASS' : 'FAIL'));
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function worker(): bool
    {
        $key = 'ops:worker-probe:'.Str::uuid();
        try {
            InfrastructureProbe::dispatch($key)->onConnection('redis');
            $deadline = microtime(true) + 15;
            do {
                $processed = Cache::store('redis')->get($key) === 'processed-by-worker';
                if (! $processed) {
                    usleep(200_000);
                }
            } while (! $processed && microtime(true) < $deadline);

            return $processed;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                Cache::store('redis')->forget($key);
            } catch (\Throwable) {
                // Probe expires even when Redis is unavailable.
            }
        }
    }

    /** @return array<string, int> */
    private function memory(): array
    {
        $data = @file_get_contents('/proc/meminfo') ?: '';
        $result = [];
        foreach (['MemTotal', 'MemAvailable'] as $field) {
            if (preg_match('/^'.$field.':\s+(\d+) kB/m', $data, $matches)) {
                $result[$field] = (int) $matches[1] * 1024;
            }
        }

        return $result;
    }

    private function tls(): bool
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host) || $host === ''
            || parse_url($url, PHP_URL_USER) !== null) {
            return false;
        }
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true,
            'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $socket = @stream_socket_client('ssl://'.$host.':'.$port, $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return false;
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $details = $certificate === null ? false : openssl_x509_parse($certificate);

        return is_array($details) && isset($details['validTo_time_t']) && $details['validTo_time_t'] >= time() + 14 * 86400;
    }
}
