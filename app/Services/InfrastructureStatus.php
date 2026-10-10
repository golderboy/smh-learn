<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class InfrastructureStatus
{
    /** @return array{ok: bool, checks: array{database: bool, redis: bool, storage: bool}} */
    public function check(): array
    {
        $checks = [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'storage' => $this->storage(),
        ];

        return ['ok' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    private function database(): bool
    {
        try {
            $row = DB::selectOne('SELECT 1 AS probe');

            return $row !== null && (int) $row->probe === 1;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function redis(): bool
    {
        $key = 'dev:connection-probe:'.Str::uuid();
        try {
            $cache = Cache::store('redis');
            $cache->put($key, 'connected', 10);

            return $cache->get($key) === 'connected';
        } catch (Throwable $exception) {
            report($exception);

            return false;
        } finally {
            try {
                Cache::store('redis')->forget($key);
            } catch (Throwable) {
                // Preserve the original failure. The key expires after 10 seconds.
            }
        }
    }

    private function storage(): bool
    {
        // Keep probes independent across root CLI and the PHP-FPM service user.
        $path = 'dev-probe-'.Str::uuid().'.txt';
        try {
            $disk = Storage::disk('local');
            if (! $disk->put($path, 'ทดสอบไฟล์ DEV')) {
                return false;
            }

            return $disk->get($path) === 'ทดสอบไฟล์ DEV';
        } catch (Throwable $exception) {
            report($exception);

            return false;
        } finally {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable) {
                // A storage failure is reported by the probe; preserve that result.
            }
        }
    }
}
