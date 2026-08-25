<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AIPrompts;
use App\Services\AI\AIResponse;
use App\Services\AI\FormAIProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini Provider Implementation
 */
class GeminiProvider implements FormAIProvider
{
    private string $model;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->model = 'gemini-1.5-flash-latest';
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        $this->timeout = 30;
    }

    /**
     * Get API key at runtime - read directly from .env file
     */
    private function getApiKey(): string
    {
        // Try getenv first (from shell export)
        $key = getenv('GEMINI_API_KEY');
        if (!empty($key)) {
            return $key;
        }

        // Read directly from .env file
        $envFile = base_path('.env');
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            if (preg_match('/^GEMINI_API_KEY=(.+)$/m', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    public function generateForm(string $prompt, array $options = []): AIResponse
    {
        $startTime = microtime(true);

        try {
            $response = $this->makeRequest(
                AIPrompts::getGenerationSystemPrompt(),
                AIPrompts::formatGenerationPrompt($prompt, $options)
            );

            return $this->processResponse($response, $startTime);
        } catch (\Exception $e) {
            return $this->handleException($e, $startTime);
        }
    }

    public function modifyForm(array $currentSchema, string $instruction, array $options = []): AIResponse
    {
        $startTime = microtime(true);

        try {
            $response = $this->makeRequest(
                AIPrompts::getModificationSystemPrompt(),
                AIPrompts::formatModificationPrompt($currentSchema, $instruction)
            );

            return $this->processResponse($response, $startTime);
        } catch (\Exception $e) {
            return $this->handleException($e, $startTime);
        }
    }

    public function getProviderName(): string
    {
        return 'gemini';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function isAvailable(): bool
    {
        return !empty($this->getApiKey());
    }

    private function makeRequest(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->getApiKey();
        $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$apiKey}";

        $response = Http::timeout($this->timeout)
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $systemPrompt . "\n\n" . $userPrompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 4096,
                ],
            ]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \Exception('Authentication failed', 401);
        }

        if ($response->status() === 429) {
            throw new \Exception('Rate limit exceeded', 429);
        }

        if (!$response->successful()) {
            Log::error('Gemini API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("API error: {$response->status()}", $response->status());
        }

        return $response->json();
    }

    private function processResponse(array $response, float $startTime): AIResponse
    {
        $latencyMs = (microtime(true) - $startTime) * 1000;

        $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        // Gemini usage metrics
        $usageMetadata = $response['usageMetadata'] ?? [];
        $inputTokens = $usageMetadata['promptTokenCount'] ?? 0;
        $outputTokens = $usageMetadata['candidatesTokenCount'] ?? 0;

        // Try to parse JSON
        $schema = $this->parseJsonOutput($content);

        if ($schema === null) {
            return AIResponse::failure(
                'Failed to parse JSON from AI response',
                AIResponse::ERROR_INVALID_JSON,
                $content,
                $inputTokens,
                $outputTokens,
                $latencyMs
            );
        }

        return AIResponse::success(
            $schema,
            $content,
            $inputTokens,
            $outputTokens,
            $latencyMs,
            ['model' => $this->model]
        );
    }

    private function parseJsonOutput(string $content): ?array
    {
        // Try direct parse
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find JSON object in content
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function handleException(\Exception $e, float $startTime): AIResponse
    {
        $latencyMs = (microtime(true) - $startTime) * 1000;
        $code = $e->getCode();

        $errorType = match (true) {
            $code === 401 || $code === 403 => AIResponse::ERROR_AUTH_FAILURE,
            $code === 429 => AIResponse::ERROR_RATE_LIMIT,
            str_contains($e->getMessage(), 'timeout') => AIResponse::ERROR_TIMEOUT,
            default => AIResponse::ERROR_PROVIDER_ERROR,
        };

        Log::warning('Gemini Provider Error', [
            'provider' => $this->getProviderName(),
            'error_type' => $errorType,
            'message' => $e->getMessage(),
        ]);

        return AIResponse::failure(
            $e->getMessage(),
            $errorType,
            null,
            0,
            0,
            $latencyMs
        );
    }
}
