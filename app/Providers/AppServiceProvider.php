<?php

namespace App\Providers;

use App\Domain\Identity\PermissionCatalog;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use App\Policies\CourseCategoryPolicy;
use App\Policies\CoursePolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['master-import-preview', 'master-import-commit'] as $limiter) {
            RateLimiter::for($limiter, fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id.':'.$request->route('kind')));
        }
        foreach (array_keys(PermissionCatalog::all()) as $code) {
            Gate::define($code, fn (User $user): bool => $user->hasPermission($code));
        }
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(CourseCategory::class, CourseCategoryPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
    }
}
