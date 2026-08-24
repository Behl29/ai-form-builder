<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\PublicFormController;
use App\Http\Controllers\Api\SubmissionController;
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

// Public form routes (no auth required)
Route::prefix('public')->group(function () {
    Route::get('/forms/{slug}', [PublicFormController::class, 'show']);
    Route::post('/forms/{slug}/submit', [PublicFormController::class, 'submit']);
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

        // Submissions
        Route::get('/forms/{form}/submissions', [SubmissionController::class, 'index'])->name('forms.submissions.index');
        Route::get('/forms/{form}/submissions/export', [SubmissionController::class, 'export'])->name('forms.submissions.export');
        Route::get('/forms/{form}/submissions/{submission}', [SubmissionController::class, 'show'])->name('forms.submissions.show');
        Route::delete('/forms/{form}/submissions/{submission}', [SubmissionController::class, 'destroy'])->name('forms.submissions.destroy');
        Route::get('/forms/{form}/submissions/{submission}/files/{file}', [SubmissionController::class, 'downloadFile'])->name('forms.submissions.files.download');
    });
});
