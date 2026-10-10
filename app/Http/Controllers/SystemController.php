<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\BrandingAssets;
use App\Services\FoundationService;
use App\Services\FoundationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function roles(): Response
    {
        Gate::authorize('roles.manage');

        return Inertia::render('Admin/Roles', ['roles' => Role::query()->with('permissions:id,name,code')->orderBy('id')->get(), 'permissions' => Permission::query()->orderBy('module')->orderBy('id')->get(['id', 'name', 'module'])]);
    }

    public function storeRole(Request $request, FoundationService $service): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $service->saveRole($request, $this->roleData($request));

        return back()->with('message', 'เพิ่มบทบาทแล้ว');
    }

    public function updateRole(Request $request, Role $role, FoundationService $service): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $service->saveRole($request, $this->roleData($request, $role), $role);

        return back()->with('message', 'บันทึกบทบาทแล้ว ผู้ใช้ที่เกี่ยวข้องต้องเข้าสู่ระบบใหม่');
    }

    /** @return array<string, mixed> */
    private function roleData(Request $request, ?Role $role = null): array
    {
        return $request->validate(['code' => [$role === null ? 'required' : 'sometimes', 'string', 'regex:/\A[a-z][a-z0-9-]{1,79}\z/', Rule::unique('roles')->ignore($role?->id)], 'name' => ['required', 'string', 'max:150'], 'permission_ids' => ['present', 'array'], 'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id']]);
    }

    public function settings(FoundationSettings $settings): Response
    {
        Gate::authorize('settings.manage');

        return Inertia::render('Admin/Settings', ['settings' => ['organization_name' => $settings->organizationName(), 'session_timeout_minutes' => $settings->sessionMinutes(), ...$settings->brandingText(), ...$settings->assetUrls()]]);
    }

    public function updateSettings(Request $request, FoundationService $service, BrandingAssets $assets): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $rules = ['organization_name' => ['required', 'string', 'max:150'], 'session_timeout_minutes' => ['required', 'integer', 'min:15', 'max:240']];
        foreach (['product_name' => 100, 'header_title' => 100, 'header_description' => 250, 'banner_text' => 250, 'login_heading' => 100, 'login_description' => 500, 'footer_text' => 250, 'support_phone' => 50] as $key => $limit) {
            $rules[$key] = ['sometimes', 'nullable', 'string', 'max:'.$limit];
        }
        $rules['product_name'] = ['sometimes', 'required', 'string', 'max:100'];
        $rules['support_email'] = ['sometimes', 'nullable', 'email', 'max:150'];
        $rules['support_url'] = ['sometimes', 'nullable', 'url:http,https', 'max:500'];
        $rules['show_sidebar_art'] = ['sometimes', 'boolean'];
        foreach (['logo', 'banner', 'favicon'] as $kind) {
            $rules[$kind.'_file'] = ['nullable', 'file', 'max:'.($kind === 'banner' ? 5120 : 2048)];
            $rules['remove_'.$kind] = ['sometimes', 'boolean'];
        }
        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules);
        $data = collect($validated)->except(['logo_file', 'banner_file', 'favicon_file', 'remove_logo', 'remove_banner', 'remove_favicon'])->map(fn ($value) => $value ?? '')->all();
        $stored = [];
        try {
            foreach (['logo', 'banner', 'favicon'] as $kind) {
                if ($request->hasFile($kind.'_file')) {
                    $metadata = $assets->store($request->file($kind.'_file'), $kind);
                    $stored[] = $metadata['path'];
                    $data['branding_'.$kind] = $metadata;
                } elseif ($request->boolean('remove_'.$kind)) {
                    $data['branding_'.$kind] = null;
                }
            }
            $service->saveSettings($request, $data);
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('message', 'บันทึกการตั้งค่าแล้ว');
    }

    public function audit(Request $request): Response
    {
        Gate::authorize('audit.view');
        $data = $request->validate(['action' => ['nullable', 'string', 'max:100']]);
        $query = AuditLog::query()->with('user:id,display_name');
        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }

        return Inertia::render('Admin/Audit', ['logs' => $query->latest('id')->paginate(20)->withQueryString(), 'filters' => $data]);
    }
}
