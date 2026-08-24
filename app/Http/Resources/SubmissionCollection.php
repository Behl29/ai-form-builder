<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SubmissionCollection extends ResourceCollection
{
    public $collects = SubmissionResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
