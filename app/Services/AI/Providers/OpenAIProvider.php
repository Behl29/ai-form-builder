<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AIPrompts;
use App\Services\AI\AIResponse;
use App\Services\AI\FormAIProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Provider Implementation
 */
class OpenAIProvider implements FormAIProvider
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;
    private int $maxTokens;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->model = config('services.openai.model', 'gpt-4o-mini');
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');
        $this->timeout = config('services.openai.timeout', 60);
        $this->maxTokens = config('services.openai.max_tokens', 4096);
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
        return 'openai';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    private function makeRequest(string $systemPrompt, string $userPrompt): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => $this->maxTokens,
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->status() === 401) {
            throw new \Exception('Authentication failed', 401);
        }

        if ($response->status() === 429) {
            throw new \Exception('Rate limit exceeded', 429);
        }

        if (!$response->successful()) {
            throw new \Exception("API error: {$response->status()}", $response->status());
        }

        return $response->json();
    }

    private function processResponse(array $response, float $startTime): AIResponse
    {
        $latencyMs = (microtime(true) - $startTime) * 1000;

        $content = $response['choices'][0]['message']['content'] ?? '';
        $usage = $response['usage'] ?? [];

        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;

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
            $code === 401 => AIResponse::ERROR_AUTH_FAILURE,
            $code === 429 => AIResponse::ERROR_RATE_LIMIT,
            str_contains($e->getMessage(), 'timeout') => AIResponse::ERROR_TIMEOUT,
            default => AIResponse::ERROR_PROVIDER_ERROR,
        };

        // Sanitize error message (remove any potential secrets)
        $sanitizedError = preg_replace('/Bearer\s+\S+/', 'Bearer [REDACTED]', $e->getMessage());

        Log::warning('AI Provider Error', [
            'provider' => $this->getProviderName(),
            'error_type' => $errorType,
            'message' => $sanitizedError,
        ]);

        return AIResponse::failure(
            $sanitizedError,
            $errorType,
            null,
            0,
            0,
            $latencyMs
        );
    }
}
