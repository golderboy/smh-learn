<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class PrepareDevStorage extends Command
{
    protected $signature = 'dev:prepare-storage';

    protected $description = 'Prepare this project DEV runtime storage for PHP-FPM, CLI and internal Nginx media reads';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'smh_learning_dev' || PHP_OS_FAMILY !== 'Linux') {
            $this->error('Storage preparation is limited to this project local Linux DEV container.');

            return self::FAILURE;
        }
        foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
            $root = realpath($path);
            if ($root === false || $root !== $path || is_link($path)) {
                throw new RuntimeException('Unexpected runtime storage path; refused.');
            }
            $this->prepare(new SplFileInfo($root), $root);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if ($entry instanceof SplFileInfo && ! $entry->isLink()) {
                    $this->prepare($entry, $root);
                }
            }
        }
        $this->info('Prepared only this project storage and bootstrap/cache.');

        return self::SUCCESS;
    }

    private function prepare(SplFileInfo $entry, string $root): void
    {
        $path = $entry->getRealPath();
        if ($path === false || ($path !== $root && ! str_starts_with($path, $root.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Runtime path boundary check failed.');
        }
        // This Docker PHP image uses www-data group 33. No other project paths are changed.
        if (! chgrp($path, 33) || ! chmod($path, $entry->isDir() ? 02775 : 0664)) {
            throw new RuntimeException('Unable to prepare runtime permissions: '.$path);
        }
    }
}
