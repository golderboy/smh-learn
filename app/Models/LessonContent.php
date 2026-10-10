<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonContent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'sha256'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'duration_seconds' => 'integer', 'file_size' => 'integer'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
