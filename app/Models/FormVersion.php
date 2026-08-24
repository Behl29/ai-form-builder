<?php

namespace App\Models;

use Database\Factories\FormVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormVersion extends Model
{
    /** @use HasFactory<FormVersionFactory> */
    use HasFactory;

    public const CHANGE_CREATED = 'created';
    public const CHANGE_UPDATED = 'updated';
    public const CHANGE_PUBLISHED = 'published';
    public const CHANGE_RESTORED = 'restored';

    public const CURRENT_SCHEMA_VERSION = '1.0';

    protected $fillable = [
        'form_id',
        'created_by',
        'version_number',
        'schema_version',
        'schema',
        'change_type',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'schema' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->is_published;
    }

    public function isImmutable(): bool
    {
        return $this->is_published;
    }

    public function publish(): bool
    {
        if ($this->is_published) {
            return false;
        }

        $this->update([
            'is_published' => true,
            'published_at' => now(),
            'change_type' => self::CHANGE_PUBLISHED,
        ]);

        return true;
    }
}
