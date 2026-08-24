<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'version_number' => $this->version_number,
            'schema_version' => $this->schema_version,
            'schema' => $this->schema,
            'change_type' => $this->change_type,
            'is_published' => $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
