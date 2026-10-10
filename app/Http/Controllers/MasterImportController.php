<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MasterImport;
use App\Services\MasterImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MasterImportController extends Controller
{
    public function index(Request $request, MasterImportService $service): Response
    {
        $kind = $request->string('kind', 'departments')->toString();
        $actor = $service->authorize($request, $kind);
        $preview = MasterImport::where('created_by', $actor->id)->where('kind', $kind)->whereNull('committed_at')->where('expires_at', '>', now())->latest('id')->first();

        return Inertia::render('Admin/Imports', [
            'kind' => $kind, 'definitions' => MasterImportService::DEFINITIONS,
            'preview' => $preview?->only(['token', 'file_name', 'rows', 'expires_at']),
            'history' => MasterImport::where('created_by', $actor->id)->whereNotNull('committed_at')->latest('id')->take(10)->get(['kind', 'file_name', 'committed_at']),
        ]);
    }

    public function template(Request $request, string $kind, MasterImportService $service): BinaryFileResponse
    {
        $service->authorize($request, $kind);

        return response()->download(base_path("docs/templates/master-{$kind}.xlsx"), "learning-{$kind}-template.xlsx");
    }

    public function preview(Request $request, string $kind, MasterImportService $service): RedirectResponse
    {
        $service->authorize($request, $kind);
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:10240']]);
        $file = $request->file('file');
        $service->preview($request, $kind, $file->getPathname(), $file->getClientOriginalName());

        return redirect('/admin/imports?kind='.$kind)->with('message', 'ตรวจไฟล์สำเร็จ กรุณาตรวจตัวอย่างก่อนยืนยัน');
    }

    public function commit(Request $request, string $kind, MasterImportService $service): RedirectResponse
    {
        $service->authorize($request, $kind);
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $count = $service->commit($request, $kind, $data['token']);

        return redirect('/admin/imports?kind='.$kind)->with('message', $count === 0 ? 'ไม่มีรายการใหม่ หรือรายการนี้บันทึกแล้ว ไม่มีการนำเข้าซ้ำ' : "นำเข้าสำเร็จ {$count} รายการ ข้ามเลขประจำตัวที่มีอยู่แล้ว");
    }
}
