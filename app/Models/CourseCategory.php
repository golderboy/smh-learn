<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CourseCategory extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this> */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_category_instructors')->withPivot('assigned_by')->withTimestamps();
    }
}
