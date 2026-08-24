<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FormController;
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
        // Forms CRUD
        Route::apiResource('forms', FormController::class);

        // Form actions
        Route::put('/forms/{form}/schema', [FormController::class, 'updateSchema'])->name('forms.schema.update');
        Route::post('/forms/{form}/publish', [FormController::class, 'publish'])->name('forms.publish');
        Route::post('/forms/{form}/unpublish', [FormController::class, 'unpublish'])->name('forms.unpublish');
        Route::post('/forms/{form}/archive', [FormController::class, 'archive'])->name('forms.archive');
        Route::post('/forms/{form}/restore', [FormController::class, 'restore'])->name('forms.restore');
        Route::post('/forms/{form}/duplicate', [FormController::class, 'duplicate'])->name('forms.duplicate');

        // Form versions
        Route::get('/forms/{form}/versions', [FormController::class, 'versions'])->name('forms.versions.index');
        Route::get('/forms/{form}/versions/{version}', [FormController::class, 'showVersion'])->name('forms.versions.show');
        Route::post('/forms/{form}/versions/{version}/restore', [FormController::class, 'restoreVersion'])->name('forms.versions.restore');
    });
});
