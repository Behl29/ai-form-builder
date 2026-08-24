<?php

namespace App\Services\AI;

/**
 * AI Provider Interface for Form Generation and Editing
 * 
 * Implementations must be stateless and replaceable.
 * Never store API keys in code - use environment variables.
 */
interface FormAIProvider
{
    /**
     * Generate a new form schema from a natural language prompt
     *
     * @param string $prompt User's description of the form
     * @param array $options Additional options (e.g., language, style)
     * @return AIResponse
     */
    public function generateForm(string $prompt, array $options = []): AIResponse;

    /**
     * Modify an existing form schema based on instructions
     *
     * @param array $currentSchema The current form schema
     * @param string $instruction Modification instructions
     * @param array $options Additional options
     * @return AIResponse
     */
    public function modifyForm(array $currentSchema, string $instruction, array $options = []): AIResponse;

    /**
     * Get the provider name for logging/audit
     */
    public function getProviderName(): string;

    /**
     * Get the model name being used
     */
    public function getModelName(): string;

    /**
     * Check if the provider is available/configured
     */
    public function isAvailable(): bool;
}
