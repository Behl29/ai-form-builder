<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

class TenantService
{
    private ?Tenant $currentTenant = null;

    public function current(): ?Tenant
    {
        return $this->currentTenant;
    }

    public function set(?Tenant $tenant): void
    {
        $this->currentTenant = $tenant;
    }

    public function setForUser(User $user): ?Tenant
    {
        $tenant = $user->currentTenant ?? $user->tenants()->first();

        if ($tenant && !$user->currentTenant) {
            $user->update(['current_tenant_id' => $tenant->id]);
        }

        $this->set($tenant);

        return $tenant;
    }

    public function switchTenant(User $user, Tenant $tenant): bool
    {
        if (!$tenant->hasUser($user)) {
            return false;
        }

        $user->update(['current_tenant_id' => $tenant->id]);
        $this->set($tenant);

        return true;
    }

    public function check(): bool
    {
        return $this->currentTenant !== null;
    }
}
