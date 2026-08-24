<?php

namespace App\Http\Requests\Form;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'success_message' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'array'],
            'schema' => ['nullable', 'array'],
        ];
    }
}
