<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WelfareStore extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /** @return BelongsToMany<User, $this> */
    public function operators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'welfare_store_users')->withPivot('assigned_by');
    }
}
