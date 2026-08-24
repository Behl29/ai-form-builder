<?php

namespace App\Traits;

use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model) {
            if (!$model->tenant_id) {
                $tenant = app(TenantService::class)->current();
                if ($tenant) {
                    $model->tenant_id = $tenant->id;
                }
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenant = app(TenantService::class)->current();
            if ($tenant) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenant->id);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, Tenant $tenant): Builder
    {
        return $query->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id);
    }
}
