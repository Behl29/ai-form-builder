<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_submission_id',
        'field_key',
        'original_name',
        'stored_name',
        'path',
        'mime_type',
        'size',
        'disk',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function getUrl(): ?string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function delete(): bool
    {
        Storage::disk($this->disk)->delete($this->path);
        return parent::delete();
    }
}
