<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function __construct(private TenantService $tenantService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->tenantService->check()) {
            return response()->json(['message' => 'No tenant context'], 403);
        }

        return $next($request);
    }
}
