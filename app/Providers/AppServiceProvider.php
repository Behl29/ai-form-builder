<?php

namespace App\Providers;

use App\Models\Form;
use App\Models\Tenant;
use App\Policies\FormPolicy;
use App\Policies\TenantPolicy;
use App\Services\TenantService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantService::class);
    }

    public function boot(): void
    {
        // Force HTTPS in production
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Form::class, FormPolicy::class);
    }
}
