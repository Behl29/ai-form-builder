<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Public auth routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Authenticated routes
Route::middleware(['auth:sanctum', SetTenantContext::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/tenants/{tenant}/switch', [AuthController::class, 'switchTenant']);

    // Tenant-scoped routes (require active tenant context)
    Route::middleware(EnsureTenantContext::class)->group(function () {
        // Future form routes will go here
    });
});
