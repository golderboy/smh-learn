<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PointTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['points' => 'integer', 'rule_snapshot' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Point ledger entries cannot be changed. Use a compensating adjustment.'));
        static::deleting(fn () => throw new LogicException('Point ledger entries cannot be deleted.'));
    }
}
