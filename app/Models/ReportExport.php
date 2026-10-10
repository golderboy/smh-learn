<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property array<string,mixed> $filters
 * @property Carbon $expires_at
 */
class ReportExport extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'scope_fingerprint'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['filters' => 'array', 'generated_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
