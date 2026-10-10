<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class CheckRelease extends Command
{
    protected $signature = 'ops:release-check {--json}';

    protected $description = 'Verify production settings without printing secrets or changing data';

    public function handle(): int
    {
        $root = User::where('root_admin_key', 'PRIMARY')->first();
        $checks = [
            'protected_admin_ready' => $root !== null && $root->username === 'admins' && $root->is_active && $root->hasRole('administrator') && ! $root->must_change_password,
            'production_environment' => app()->environment('production'),
            'debug_disabled' => config('app.debug') === false,
            'https_url' => parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https',
            'database_not_dev_or_test' => ! in_array(config('database.connections.mysql.database'), ['smh_learning_dev', 'smh_learning_test'], true),
            'database_user_not_root' => config('database.connections.mysql.username') !== 'root',
            'database_secret_present' => is_string(config('database.connections.mysql.password')) && config('database.connections.mysql.password') !== '',
            'app_key_present' => is_string(config('app.key')) && strlen(config('app.key')) >= 32,
            'secure_session_cookie' => config('session.secure') === true,
            'http_only_session' => config('session.http_only') === true,
            'encrypted_session' => config('session.encrypt') === true,
            'same_site_session' => config('session.same_site') === 'lax',
            'redis_services' => config('session.driver') === 'redis' && config('cache.default') === 'redis' && config('queue.default') === 'redis',
            'build_exists' => is_file(public_path('build/manifest.json')),
            'no_vite_dev_server' => ! is_file(public_path('hot')),
            'no_demo_accounts' => ! User::query()->where('is_demo', true)->exists(),
            'no_dev_diagnostics' => ! Route::has('dev.home') && ! Route::has('dev.status'),
            'production_log_level' => in_array(config('logging.channels.daily.level'), ['info', 'notice', 'warning', 'error', 'critical'], true),
            'password_change_required' => config('identity.require_initial_password_change') === true,
            'thaid_disabled_until_accepted' => config('identity.thaid.enabled') === false && config('identity.thaid.adapter_ready') === false,
        ];
        $result = ['ok' => ! in_array(false, $checks, true), 'checks' => $checks];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line($name.': '.($passed ? 'PASS' : 'FAIL'));
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
