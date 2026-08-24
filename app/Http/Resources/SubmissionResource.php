<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'form_version_id' => $this->form_version_id,
            'version_number' => $this->whenLoaded('formVersion', fn() => $this->formVersion->version_number),
            'data' => $this->data,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'files' => SubmissionFileResource::collection($this->whenLoaded('files')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
