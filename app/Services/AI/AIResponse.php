<?php

namespace App\Services\AI;

/**
 * Standardized AI Response object
 */
class AIResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?array $schema = null,
        public readonly ?string $rawOutput = null,
        public readonly ?string $error = null,
        public readonly ?string $errorType = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $latencyMs = 0,
        public readonly array $metadata = [],
    ) {}

    public static function success(
        array $schema,
        string $rawOutput,
        int $inputTokens = 0,
        int $outputTokens = 0,
        float $latencyMs = 0,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            schema: $schema,
            rawOutput: $rawOutput,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            latencyMs: $latencyMs,
            metadata: $metadata,
        );
    }

    public static function failure(
        string $error,
        string $errorType,
        ?string $rawOutput = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        float $latencyMs = 0,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            error: $error,
            errorType: $errorType,
            rawOutput: $rawOutput,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            latencyMs: $latencyMs,
            metadata: $metadata,
        );
    }

    public function getTotalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    // Error types
    public const ERROR_INVALID_JSON = 'invalid_json';
    public const ERROR_INVALID_SCHEMA = 'invalid_schema';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_RATE_LIMIT = 'rate_limit';
    public const ERROR_AUTH_FAILURE = 'auth_failure';
    public const ERROR_PROVIDER_ERROR = 'provider_error';
    public const ERROR_REPAIR_FAILED = 'repair_failed';
}
