<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field_key' => $this->field_key,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
