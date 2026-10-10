<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['randomize_questions' => 'boolean', 'randomize_choices' => 'boolean', 'question_count' => 'integer', 'time_limit_minutes' => 'integer'];
    }
}
