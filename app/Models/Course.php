<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<CourseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    /** @return HasMany<CourseVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class);
    }
}
