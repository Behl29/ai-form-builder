<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function __construct(private TenantService $tenantService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $this->tenantService->setForUser($user);
        }

        return $next($request);
    }
}
