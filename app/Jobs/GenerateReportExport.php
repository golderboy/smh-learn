<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReportFileWriter;
use App\Services\ReportingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportingService $reports, ReportFileWriter $writer, AuditService $audit): void
    {
        $export = DB::transaction(function (): ?ReportExport {
            $record = ReportExport::whereKey($this->exportId)->lockForUpdate()->first();
            if ($record === null || $record->status === 'READY' || $record->status === 'FAILED' || ($record->status === 'RUNNING' && $record->updated_at->greaterThan(now()->subSeconds($this->timeout)))) {
                return null;
            }
            $record->update(['status' => 'RUNNING', 'error_message' => null]);

            return $record;
        });
        if ($export === null) {
            return;
        }
        $owner = User::find($export->user_id);
        if ($owner === null || ! $owner->is_active || $export->expires_at->isPast() || ! hash_equals($export->scope_fingerprint, $reports->fingerprint($owner, $export->filters))) {
            $export->update(['status' => 'FAILED', 'error_message' => 'สิทธิ์เปลี่ยนหรือคำขอหมดอายุ กรุณาสร้างรายงานใหม่']);

            return;
        }
        $disk = Storage::disk('reports');
        $relative = 'exports/'.$export->token.'.'.$export->format;
        $partial = $relative.'.part';
        try {
            $disk->makeDirectory('exports');
            $report = $reports->report($owner, $export->report_type, $export->filters);
            $writer->write($export->format, $disk->path($partial), $export->report_type, $report['columns'], $report['rows']);
            $fresh = User::findOrFail($owner->id);
            if (! $fresh->is_active || ! hash_equals($export->scope_fingerprint, $reports->fingerprint($fresh, $export->filters))) {
                $export->update(['status' => 'FAILED', 'error_message' => 'สิทธิ์เปลี่ยนระหว่างสร้างรายงาน กรุณาสร้างใหม่']);

                return;
            }
            $disk->move($partial, $relative);
            chmod($disk->path($relative), 0644);
            $request = Request::create('/reports/exports');
            $request->setUserResolver(fn () => $owner);
            DB::transaction(function () use ($export, $relative, $report, $disk, $request, $audit): void {
                $export->update(['status' => 'READY', 'storage_path' => $relative, 'row_count' => $report['rows']->count(), 'file_size' => $disk->size($relative), 'sha256' => hash_file('sha256', $disk->path($relative)), 'generated_at' => now()]);
                $audit->record($request, 'reports.exported', 'report_export', $export->id, new: ['format' => $export->format, 'rows' => $report['rows']->count()]);
            });
        } catch (Throwable $exception) {
            $export->update(['status' => 'QUEUED']);
            throw $exception;
        } finally {
            $disk->delete($partial);
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReportExport::whereKey($this->exportId)->update(['status' => 'FAILED', 'error_message' => 'สร้างไฟล์ไม่สำเร็จ กรุณาลองสร้างใหม่หรือติดต่อผู้ดูแล']);
    }
}
