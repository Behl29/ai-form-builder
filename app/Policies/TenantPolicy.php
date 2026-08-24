<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isOwnerOf($tenant);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->isOwnerOf($tenant);
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $user->isOwnerOf($tenant);
    }
}
