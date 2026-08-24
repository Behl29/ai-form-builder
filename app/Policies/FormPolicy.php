<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;
use App\Services\TenantService;

class FormPolicy
{
    public function __construct(private TenantService $tenantService)
    {
    }

    public function viewAny(User $user): bool
    {
        return $this->tenantService->check();
    }

    public function view(User $user, Form $form): bool
    {
        return $this->belongsToCurrentTenant($form);
    }

    public function create(User $user): bool
    {
        return $this->tenantService->check();
    }

    public function update(User $user, Form $form): bool
    {
        return $this->belongsToCurrentTenant($form);
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->belongsToCurrentTenant($form);
    }

    public function publish(User $user, Form $form): bool
    {
        return $this->belongsToCurrentTenant($form);
    }

    public function duplicate(User $user, Form $form): bool
    {
        return $this->belongsToCurrentTenant($form);
    }

    private function belongsToCurrentTenant(Form $form): bool
    {
        $tenant = $this->tenantService->current();
        return $tenant && $form->tenant_id === $tenant->id;
    }
}
