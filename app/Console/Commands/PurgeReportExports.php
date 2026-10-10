<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeReportExports extends Command
{
    protected $signature = 'reports:purge';

    protected $description = 'Remove expired private report files while preserving request metadata';

    public function handle(): int
    {
        $count = 0;
        foreach (ReportExport::where('expires_at', '<=', now())->where('status', '!=', 'EXPIRED')->cursor() as $export) {
            foreach (array_filter([$export->storage_path, 'exports/'.$export->token.'.'.$export->format.'.part']) as $path) {
                if (preg_match('#^exports/[a-f0-9-]{36}\.(xlsx|csv|pdf)(\.part)?$#', $path)) {
                    Storage::disk('reports')->delete($path);
                }
            }
            $export->update(['status' => 'EXPIRED', 'storage_path' => null]);
            $count++;
        }
        $this->info('Expired report files removed: '.$count);

        return self::SUCCESS;
    }
}
