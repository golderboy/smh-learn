<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property list<array<string, mixed>> $rows
 * @property Carbon $expires_at
 * @property Carbon|null $committed_at
 */
class QuestionImport extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rows' => 'array', 'expires_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
