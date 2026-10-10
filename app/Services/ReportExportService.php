<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReportExportService
{
    public function __construct(private readonly ReportingService $reports, private readonly AuditService $audit) {}

    /** @param array<string,mixed> $filters */
    public function create(Request $request, User $user, string $type, string $format, array $filters, string $key): ReportExport
    {
        abort_if($type === 'question' && $this->reports->scope($user, ($filters['audience'] ?? '') === 'mine')['personal'], 403);

        return DB::transaction(function () use ($request, $user, $type, $format, $filters, $key): ReportExport {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = ReportExport::where('user_id', $user->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                abort_unless($existing->report_type === $type && $existing->format === $format && $existing->filters === $filters && $existing->scope_fingerprint === $this->reports->fingerprint($user, $filters), 409, 'คำขอนี้เคยใช้กับรายงานอื่นแล้ว กรุณาสร้างคำขอใหม่');

                return $existing;
            }
            $export = ReportExport::create(['token' => (string) Str::uuid(), 'user_id' => $user->id, 'request_key' => $key, 'report_type' => $type, 'format' => $format, 'filters' => $filters, 'scope_fingerprint' => $this->reports->fingerprint($user, $filters), 'status' => 'QUEUED', 'expires_at' => now()->addDays(7)]);
            GenerateReportExport::dispatch($export->id)->afterCommit();
            $this->audit->record($request, 'reports.export_requested', 'report_export', $export->id, new: ['report_type' => $type, 'format' => $format]);

            return $export;
        });
    }

    public function canDownload(User $user, ReportExport $export): bool
    {
        return $user->is_active && $export->user_id === $user->id && $export->status === 'READY' && $export->expires_at->isFuture() && hash_equals($export->scope_fingerprint, $this->reports->fingerprint($user, $export->filters));
    }

    /** @return array<string,mixed> */
    public function present(User $user, ReportExport $export): array
    {
        return [...$export->only(['token', 'report_type', 'format', 'status', 'row_count', 'file_size', 'error_message', 'created_at', 'expires_at']), 'can_download' => $this->canDownload($user, $export)];
    }
}
