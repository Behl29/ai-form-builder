<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ImportJob extends Model
{
    use HasFactory;

    protected $table = 'import_jobs';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PARSED = 'parsed';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const TYPE_DOCX = 'docx';
    public const TYPE_XLSX = 'xlsx';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'form_id',
        'job_uuid',
        'import_type',
        'status',
        'original_filename',
        'file_path',
        'file_size',
        'parsed_elements',
        'corrected_elements',
        'result_schema',
        'validation_errors',
        'warnings',
        'error_message',
        'use_ai_classification',
        'ai_job_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'parsed_elements' => 'array',
        'corrected_elements' => 'array',
        'result_schema' => 'array',
        'validation_errors' => 'array',
        'warnings' => 'array',
        'use_ai_classification' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ImportJob $job) {
            if (empty($job->job_uuid)) {
                $job->job_uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function aiJob(): BelongsTo
    {
        return $this->belongsTo(AIJob::class, 'ai_job_id');
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function markParsed(array $elements, array $warnings = []): void
    {
        $this->update([
            'status' => self::STATUS_PARSED,
            'parsed_elements' => $elements,
            'warnings' => $warnings,
        ]);
    }

    public function markSucceeded(array $schema): void
    {
        $this->update([
            'status' => self::STATUS_SUCCEEDED,
            'result_schema' => $schema,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage, ?array $validationErrors = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'validation_errors' => $validationErrors,
            'completed_at' => now(),
        ]);
    }

    public function updateCorrections(array $correctedElements): void
    {
        $this->update(['corrected_elements' => $correctedElements]);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isParsed(): bool
    {
        return $this->status === self::STATUS_PARSED;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED]);
    }

    public function canCommit(): bool
    {
        return $this->status === self::STATUS_PARSED;
    }

    public function getElementsForPreview(): array
    {
        return $this->corrected_elements ?? $this->parsed_elements ?? [];
    }
}
