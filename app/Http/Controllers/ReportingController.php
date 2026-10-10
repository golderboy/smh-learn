<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReportExport;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReportExportService;
use App\Services\ReportFileWriter;
use App\Services\ReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportingController extends Controller
{
    public function index(Request $request, ReportingService $reports, ReportExportService $exports): Response
    {
        $user = $this->actor($request);
        $filters = $this->filters($request);
        $type = $request->validate(['type' => ['sometimes', Rule::in(array_keys(ReportFileWriter::TYPES))]])['type'] ?? 'individual';
        $scope = $reports->scope($user, ($filters['audience'] ?? '') === 'mine');
        abort_if($type === 'question' && $scope['personal'], 403);

        return Inertia::render('Reports/Index', [
            'filters' => $filters, 'reportType' => $type, 'professional' => ! $reports->scope($user)['personal'],
            'report' => function () use ($reports, $user, $type, $filters, $request): array {
                $result = $reports->report($user, $type, $filters);
                $page = max(1, $request->integer('page', 1));

                return [...$result, 'rows' => new LengthAwarePaginator($result['rows']->forPage($page, 25)->values(), $result['rows']->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()])];
            },
            'options' => fn () => $reports->referenceOptions($user, ($filters['audience'] ?? '') === 'mine'),
            'exports' => fn () => ReportExport::where('user_id', $user->id)->latest('id')->limit(10)->get()->map(fn ($export): array => $exports->present($user, $export)),
        ]);
    }

    public function export(Request $request, ReportExportService $exports): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(ReportFileWriter::TYPES))], 'format' => ['required', Rule::in(['xlsx', 'csv', 'pdf'])], 'request_key' => ['required', 'uuid']]);
        $exports->create($request, $this->actor($request), $data['type'], $data['format'], $this->filters($request), $data['request_key']);

        return back()->with('message', 'เข้าคิวสร้างไฟล์แล้ว ระบบจะแสดงปุ่มดาวน์โหลดเมื่อพร้อม');
    }

    public function download(Request $request, ReportExport $export, ReportExportService $exports, AuditService $audit): BinaryFileResponse
    {
        $user = $this->actor($request);
        abort_unless($export->user_id === $user->id, 404);
        abort_unless($exports->canDownload($user, $export), 403, 'ไฟล์ไม่พร้อม หมดอายุ หรือสิทธิ์เปลี่ยน กรุณาสร้างรายงานใหม่');
        $path = $export->storage_path;
        abort_unless(is_string($path) && preg_match('#^exports/[a-f0-9-]{36}\.(xlsx|csv|pdf)$#', $path) === 1 && Storage::disk('reports')->exists($path), 404);
        $audit->record($request, 'reports.downloaded', 'report_export', $export->id);
        $mime = match ($export->format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8', default => 'application/pdf'
        };

        return response()->download(Storage::disk('reports')->path($path), 'smh-'.$export->report_type.'-'.$export->token.'.'.$export->format, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'audience' => ['sometimes', Rule::in(['scope', 'mine'])],
            'fiscal_year' => ['nullable', 'integer', 'min:2400', 'max:3000'],
            'course_id' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'], 'position_id' => ['nullable', 'integer', 'min:1'], 'user_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'status' => ['nullable', Rule::in(['COMPLETED', 'CANCELLED', 'NOT_STARTED', 'IN_PROGRESS', 'OVERDUE'])],
            'exam_type' => ['nullable', Rule::in(['PRETEST', 'POSTTEST'])],
        ]);
        $data = array_filter($data, fn ($value): bool => $value !== null && $value !== '');
        foreach (['fiscal_year', 'course_id', 'category_id', 'department_id', 'position_id', 'user_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        ksort($data);

        return $data;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }
}
