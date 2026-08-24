<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'tenant_id' => Tenant::factory(),
            'created_by' => User::factory(),
            'title' => $title,
            'description' => fake()->optional()->paragraph(),
            'slug' => Str::slug($title) . '-' . Str::random(8),
            'status' => Form::STATUS_DRAFT,
            'success_message' => 'Thank you for your submission!',
            'settings' => null,
            'current_version_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Form::STATUS_PUBLISHED,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Form::STATUS_ARCHIVED,
        ]);
    }
}
