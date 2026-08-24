<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 */
class FormVersionFactory extends Factory
{
    protected $model = FormVersion::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'created_by' => User::factory(),
            'version_number' => 1,
            'schema_version' => FormSchemaContract::SCHEMA_VERSION,
            'schema' => $this->generateValidSchema(),
            'change_type' => FormVersion::CHANGE_CREATED,
            'is_published' => false,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
            'published_at' => now(),
            'change_type' => FormVersion::CHANGE_PUBLISHED,
        ]);
    }

    public function withSchema(array $schema): static
    {
        return $this->state(fn (array $attributes) => [
            'schema' => $schema,
        ]);
    }

    private function generateValidSchema(): array
    {
        return [
            'schemaVersion' => FormSchemaContract::SCHEMA_VERSION,
            'metadata' => [
                'title' => fake()->sentence(3),
                'description' => fake()->optional()->paragraph(),
            ],
            'settings' => [
                'submitButtonText' => 'Submit',
                'showProgressBar' => false,
                'allowSaveDraft' => false,
            ],
            'sections' => [
                [
                    'id' => 'section_' . fake()->uuid(),
                    'title' => 'Section 1',
                    'description' => '',
                    'fields' => [
                        [
                            'id' => 'field_' . fake()->uuid(),
                            'key' => 'name',
                            'type' => 'text',
                            'label' => 'Name',
                            'required' => true,
                        ],
                        [
                            'id' => 'field_' . fake()->uuid(),
                            'key' => 'email',
                            'type' => 'email',
                            'label' => 'Email',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}
