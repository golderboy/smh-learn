<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'issuer', 'subject', 'verified_at'];

    protected $hidden = ['subject'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
