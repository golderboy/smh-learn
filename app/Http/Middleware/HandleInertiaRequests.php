<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\PermissionCatalog;
use App\Models\User;
use App\Services\FoundationSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $settings = app(FoundationSettings::class);
        $branding = $settings->brandingText();
        $user = $request->user();
        $auth = null;
        if ($user instanceof User && $user->is_active) {
            $user->loadMissing(['department', 'position', 'roles.permissions']);
            $auth = [
                'id' => $user->id, 'display_name' => $user->display_name,
                'department' => $user->department?->name, 'position' => $user->position?->name,
                'roles' => $user->roles->map(fn ($role) => ['code' => $role->code, 'name' => $role->name])->values(),
                'permissions' => $user->isRootAdministrator() ? array_keys(PermissionCatalog::all()) : $user->roles->flatMap(fn ($role) => $role->permissions->pluck('code'))->unique()->values(),
                'must_change_password' => $user->must_change_password,
            ];
        }

        return array_merge(parent::share($request), [
            'app' => ['name' => $branding['product_name'], 'organization' => $settings->organizationName(), 'timezone' => config('app.timezone'), 'branding' => [...$branding, ...$settings->assetUrls()]],
            'auth' => ['user' => $auth],
            'flash' => ['message' => fn () => $request->session()->get('message')],
        ]);
    }
}
