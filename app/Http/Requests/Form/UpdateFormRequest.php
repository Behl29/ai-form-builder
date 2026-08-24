<?php

namespace App\Http\Requests\Form;

use App\Services\TenantService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantService::class)->current()?->id;
        $formId = $this->route('form')?->id;

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'slug' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('forms', 'slug')
                    ->where('tenant_id', $tenantId)
                    ->ignore($formId),
            ],
            'success_message' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'array'],
            'schema' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must contain only lowercase letters, numbers, and hyphens.',
            'slug.unique' => 'This slug is already in use.',
        ];
    }
}
