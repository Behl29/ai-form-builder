<?php

namespace App\Providers;

use App\Services\AI\AIFormService;
use App\Services\AI\AISchemaRepair;
use App\Services\AI\FormAIProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\MockAIProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\FormSchema\FormSchemaValidator;
use App\Services\VersionService;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register AI Provider based on config
        $this->app->singleton(FormAIProvider::class, function ($app) {
            $provider = config('services.ai.provider', 'openai');

            return match ($provider) {
                'mock' => new MockAIProvider(),
                'gemini' => new GeminiProvider(),
                'openai' => new OpenAIProvider(),
                default => new OpenAIProvider(),
            };
        });

        // Register Schema Repair
        $this->app->singleton(AISchemaRepair::class, function ($app) {
            return new AISchemaRepair($app->make(FormSchemaValidator::class));
        });

        // Register AI Form Service
        $this->app->singleton(AIFormService::class, function ($app) {
            return new AIFormService(
                $app->make(FormAIProvider::class),
                $app->make(FormSchemaValidator::class),
                $app->make(AISchemaRepair::class),
                $app->make(VersionService::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
