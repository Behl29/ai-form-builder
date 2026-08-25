<?php

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PublicFormController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\VersionController;
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
Route::prefix('public')->middleware('rate.limit:public_submit')->group(function () {
    Route::get('/forms/{slug}', [PublicFormController::class, 'show']);
    Route::post('/forms/{slug}/submit', [PublicFormController::class, 'submit']);
});

// Public auth routes
Route::middleware('rate.limit:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

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
        Route::get('/forms/{form}/versions', [VersionController::class, 'index'])->name('forms.versions.index');
        Route::get('/forms/{form}/versions/{version}', [VersionController::class, 'show'])->name('forms.versions.show');
        Route::post('/forms/{form}/versions/compare', [VersionController::class, 'compare'])->name('forms.versions.compare');
        Route::post('/forms/{form}/versions/{version}/rollback', [VersionController::class, 'rollback'])->name('forms.versions.rollback');
        Route::post('/forms/{form}/versions/{version}/publish', [VersionController::class, 'publish'])->name('forms.versions.publish');

        // Submissions
        Route::get('/forms/{form}/submissions', [SubmissionController::class, 'index'])->name('forms.submissions.index');
        Route::get('/forms/{form}/submissions/export', [SubmissionController::class, 'export'])->middleware('rate.limit:export')->name('forms.submissions.export');
        Route::get('/forms/{form}/submissions/{submission}', [SubmissionController::class, 'show'])->name('forms.submissions.show');
        Route::delete('/forms/{form}/submissions/{submission}', [SubmissionController::class, 'destroy'])->name('forms.submissions.destroy');
        Route::get('/forms/{form}/submissions/{submission}/files/{file}', [SubmissionController::class, 'downloadFile'])->name('forms.submissions.files.download');

        // AI Form Generation
        Route::prefix('ai')->group(function () {
            Route::get('/provider', [AIController::class, 'providerInfo'])->name('ai.provider');
            Route::get('/jobs', [AIController::class, 'listJobs'])->name('ai.jobs.index');
            Route::get('/jobs/{jobUuid}', [AIController::class, 'status'])->name('ai.jobs.status');
            
            // Rate limited AI actions
            Route::middleware('rate.limit:ai_generate')->group(function () {
                Route::post('/generate', [AIController::class, 'generate'])->name('ai.generate');
                Route::post('/create-form', [AIController::class, 'createForm'])->name('ai.create-form');
            });
            
            Route::post('/forms/{form}/modify', [AIController::class, 'modify'])->middleware('rate.limit:ai_modify')->name('ai.modify');
            Route::post('/forms/{form}/preview-diff', [AIController::class, 'previewDiff'])->name('ai.preview-diff');
            Route::post('/forms/{form}/accept', [AIController::class, 'accept'])->name('ai.accept');
        });

        // Document Import
        Route::prefix('import')->middleware('rate.limit:import')->group(function () {
            Route::get('/', [ImportController::class, 'index'])->name('import.index');
            Route::post('/upload', [ImportController::class, 'upload'])->name('import.upload');
            Route::get('/{jobUuid}', [ImportController::class, 'status'])->name('import.status');
            Route::get('/{jobUuid}/preview', [ImportController::class, 'preview'])->name('import.preview');
            Route::post('/{jobUuid}/correct', [ImportController::class, 'correct'])->name('import.correct');
            Route::post('/{jobUuid}/commit', [ImportController::class, 'commit'])->name('import.commit');
            Route::delete('/{jobUuid}', [ImportController::class, 'cancel'])->name('import.cancel');
        });
    });
});
