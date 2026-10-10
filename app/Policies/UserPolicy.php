<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function update(User $actor, User $target): bool
    {
        return ! $target->isRootAdministrator() && $actor->hasPermission('users.manage') && (! $target->hasRole('administrator') || $actor->hasRole('administrator'));
    }
}
