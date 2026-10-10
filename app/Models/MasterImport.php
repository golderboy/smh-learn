<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property list<array{line: int, data: array<string, mixed>, skip: bool}> $rows
 * @property Carbon $expires_at
 * @property Carbon|null $committed_at
 */
class MasterImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rows' => 'encrypted:array', 'expires_at' => 'datetime', 'committed_at' => 'datetime'];
    }
}
