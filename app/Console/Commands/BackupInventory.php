<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BackupInventory extends Command
{
    protected $signature = 'ops:backup-inventory';

    protected $description = 'Read-only production row/file hashes for backup reconciliation; never prints personal data';

    public function handle(): int
    {
        if (! app()->environment('production') || in_array(DB::connection()->getDatabaseName(), ['smh_learning_dev', 'smh_learning_test'], true)
            || User::where('is_demo', true)->exists()) {
            $this->error('Production inventory refuses DEV/test databases and demo accounts.');

            return self::FAILURE;
        }
        $tables = [];
        foreach (DB::select('SHOW TABLES') as $item) {
            $table = (string) array_values((array) $item)[0];
            if (! preg_match('/^[a-z_]+$/D', $table)) {
                throw new RuntimeException('Unexpected table identifier.');
            }
            $query = DB::table($table);
            foreach (DB::getSchemaBuilder()->getColumnListing($table) as $column) {
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
            $root = storage_path('app/'.$folder);
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Backup inventory refuses symbolic links.');
                }
                if ($file->isFile()) {
                    $relative = substr($file->getPathname(), strlen(storage_path('app')) + 1);
                    $files[$relative] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
                }
            }
        }
        ksort($tables);
        ksort($files);
        $this->line(json_encode(['environment' => 'production', 'maintenance' => app()->isDownForMaintenance(), 'tables' => $tables, 'files' => $files], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
