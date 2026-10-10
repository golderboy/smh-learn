<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array{value: mixed} $value */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
