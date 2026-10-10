<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    /** @return BelongsTo<CourseModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /** @return HasMany<LessonContent, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(LessonContent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsToMany<LessonContent, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(LessonContent::class, 'lesson_document_links', 'lesson_id', 'content_id')->orderByPivot('sort_order');
    }
}
