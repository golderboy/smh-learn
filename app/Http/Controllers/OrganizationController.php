<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Services\FoundationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(string $kind): Response
    {
        Gate::authorize('organization.manage');
        $model = $kind === 'departments' ? new Department : new Position;

        return Inertia::render('Admin/Organization', ['kind' => $kind, 'records' => $model->newQuery()->orderBy('id')->paginate(20)]);
    }

    public function store(Request $request, string $kind, FoundationService $service): RedirectResponse
    {
        return $this->save($request, $kind, $service);
    }

    public function update(Request $request, string $kind, int $record, FoundationService $service): RedirectResponse
    {
        return $this->save($request, $kind, $service, $record);
    }

    private function save(Request $request, string $kind, FoundationService $service, ?int $id = null): RedirectResponse
    {
        Gate::authorize('organization.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:50', Rule::unique($kind)->ignore($id)], 'name' => ['required', 'string', 'max:150'], 'is_active' => ['required', 'boolean']]);
        $service->saveOrganization($request, $kind, $data, $id);

        return back()->with('message', 'บันทึกข้อมูลแล้ว');
    }
}
