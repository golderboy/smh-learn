<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expires_at
 * @property array<string, mixed> $rules_snapshot
 */
class ExamAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['rules_snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rules_snapshot' => 'array', 'started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime', 'passed' => 'boolean', 'revision' => 'integer'];
    }
}
