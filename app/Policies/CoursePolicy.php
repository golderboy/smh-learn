<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;

class CoursePolicy
{
    public function manage(User $user, Course $course): bool
    {
        return $this->inCategory($user, $course->category);
    }

    public function inCategory(User $user, CourseCategory $category): bool
    {
        return $user->is_active && ($user->hasRole('administrator') || $user->supervises($category));
    }
}
