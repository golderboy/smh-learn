<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\InfrastructureProbe;
use App\Services\InfrastructureStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CheckDevEnvironment extends Command
{
    protected $signature = 'dev:check {--queue : Dispatch and await a real Redis worker job} {--scheduler : Check the scheduler heartbeat}';

    protected $description = 'Verify DEV database, Redis, storage, queue and scheduler';

    public function handle(InfrastructureStatus $status): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is available only in the local DEV environment.');

            return self::FAILURE;
        }

        $result = $status->check();
        foreach ($result['checks'] as $name => $passed) {
            $this->line($name.': '.($passed ? 'PASS' : 'FAIL'));
        }
        $ok = $result['ok'];

        if ($this->option('queue')) {
            $key = 'dev:queue-probe:'.Str::uuid();
            InfrastructureProbe::dispatch($key)->onConnection('redis');
            $deadline = microtime(true) + 15;
            $processed = false;
            do {
                $processed = Cache::store('redis')->get($key) === 'processed-by-worker';
                if (! $processed) {
                    usleep(200_000);
                }
            } while (! $processed && microtime(true) < $deadline);
            Cache::store('redis')->forget($key);
            $this->line('queue: '.($processed ? 'PASS' : 'FAIL'));
            $ok = $ok && $processed;
        }

        if ($this->option('scheduler')) {
            $heartbeat = Cache::store('redis')->get('dev:scheduler-heartbeat');
            // RedisStore returns un-serialized numeric values as numeric strings.
            $timestamp = filter_var($heartbeat, FILTER_VALIDATE_INT);
            $fresh = $timestamp !== false
                && $timestamp >= now()->timestamp - 90
                && $timestamp <= now()->timestamp;
            $this->line('scheduler: '.($fresh ? 'PASS' : 'FAIL (wait for the next minute and retry)'));
            $ok = $ok && $fresh;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
