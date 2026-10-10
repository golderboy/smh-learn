<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointRule extends Model
{
    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['points' => 'integer', 'is_active' => 'boolean'];
    }
}
