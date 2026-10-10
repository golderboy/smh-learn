<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CourseCategory;
use App\Models\User;

class CourseCategoryPolicy
{
    public function view(User $user, CourseCategory $category): bool
    {
        return $user->hasPermission('categories.manage') || ($user->hasPermission('categories.view') && $user->supervises($category));
    }

    public function update(User $user, CourseCategory $category): bool
    {
        return $user->hasPermission('categories.manage');
    }
}
