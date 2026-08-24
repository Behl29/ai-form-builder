<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FormSubmission extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'form_id',
        'form_version_id',
        'data',
        'status',
        'submission_token',
        'ip_address',
        'user_agent',
        'submitted_at',
    ];

    protected $casts = [
        'data' => 'array',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FormSubmission $submission) {
            if (empty($submission->submission_token)) {
                $submission->submission_token = Str::random(64);
            }
            if (empty($submission->submitted_at)) {
                $submission->submitted_at = now();
            }
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    public function getFieldValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
