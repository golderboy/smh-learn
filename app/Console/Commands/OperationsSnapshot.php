<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationsSnapshot extends Command
{
    protected $signature = 'ops:snapshot {--database= : Isolated restore database only} {--storage= : Isolated restore storage root only}';

    protected $description = 'DEV backup reconciliation: row hashes and file hashes, no personal data';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev') {
            $this->error('Snapshot tooling requires the local DEV database.');

            return self::FAILURE;
        }
        if ($database = $this->option('database')) {
            if (! preg_match('/^smh_restore_[a-f0-9]{12}$/D', $database)) {
                $this->error('Refusing a non-isolated restore database.');

                return self::FAILURE;
            }
            config(['database.connections.mysql.database' => $database, 'database.connections.mysql.username' => 'root',
                'database.connections.mysql.password' => config('operations.dev_restore_password')]);
            DB::purge('mysql');
        }
        $root = storage_path('app');
        if ($storage = $this->option('storage')) {
            $allowed = realpath(storage_path('app/restore-drills'));
            $resolved = realpath($storage);
            if ($allowed === false || $resolved === false || ! str_starts_with($resolved, $allowed.DIRECTORY_SEPARATOR)) {
                $this->error('Refusing storage outside the restore drill directory.');

                return self::FAILURE;
            }
            $root = $resolved;
        }
        $tables = [];
        foreach (DB::select('SHOW TABLES') as $item) {
            $table = (string) array_values((array) $item)[0];
            if (! preg_match('/^[a-z_]+$/D', $table)) {
                throw new RuntimeException('Unexpected table identifier.');
            }
            $columns = DB::getSchemaBuilder()->getColumnListing($table);
            $query = DB::table($table);
            // Deterministic order including pivot tables without an id.
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($query->cursor() as $row) {
                hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $count++;
            }
            $tables[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
        }
        $files = [];
        foreach (['private', 'public'] as $folder) {
            $path = $root.DIRECTORY_SEPARATOR.$folder;
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Backup storage must not contain symbolic links.');
                }
                if ($file->isFile()) {
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                    $files[$relative] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
                }
            }
        }
        ksort($tables);
        ksort($files);
        $this->line(json_encode(['environment' => app()->environment(), 'tables' => $tables, 'files' => $files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
