<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AIJob extends Model
{
    use HasFactory;

    protected $table = 'ai_jobs';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const TYPE_GENERATE = 'generate';
    public const TYPE_MODIFY = 'modify';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'form_id',
        'job_uuid',
        'request_type',
        'status',
        'retry_count',
        'provider',
        'model',
        'prompt',
        'options',
        'result_schema',
        'validation_errors',
        'repair_log',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'error_type',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'options' => 'array',
        'result_schema' => 'array',
        'validation_errors' => 'array',
        'repair_log' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $hidden = [
        'prompt', // Don't expose in API responses by default
    ];

    protected static function booted(): void
    {
        static::creating(function (AIJob $job) {
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

    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function markSucceeded(array $schema, array $repairLog = []): void
    {
        $this->update([
            'status' => self::STATUS_SUCCEEDED,
            'result_schema' => $schema,
            'repair_log' => $repairLog,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $errorType, string $errorMessage, ?array $validationErrors = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_type' => $errorType,
            'error_message' => $this->sanitizeErrorMessage($errorMessage),
            'validation_errors' => $validationErrors,
            'completed_at' => now(),
        ]);
    }

    public function incrementRetry(): void
    {
        $this->increment('retry_count');
    }

    public function recordMetrics(int $inputTokens, int $outputTokens, float $latencyMs): void
    {
        $this->update([
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => (int) $latencyMs,
        ]);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
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

    public function getTotalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    private function sanitizeErrorMessage(string $message): string
    {
        // Remove potential API keys or secrets
        $message = preg_replace('/Bearer\s+\S+/', 'Bearer [REDACTED]', $message);
        $message = preg_replace('/api[_-]?key[=:]\s*\S+/i', 'api_key=[REDACTED]', $message);
        $message = preg_replace('/sk-[a-zA-Z0-9]+/', '[REDACTED]', $message);

        return $message;
    }
}
