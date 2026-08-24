<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitByAction
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $key = $this->resolveKey($request, $action);
        $limits = $this->getLimits($action);

        if ($this->limiter->tooManyAttempts($key, $limits['max'])) {
            $retryAfter = $this->limiter->availableIn($key);

            return response()->json([
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limits['max'],
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        $this->limiter->hit($key, $limits['decay']);

        $response = $next($request);

        // Only add headers if response supports it (not StreamedResponse)
        if (method_exists($response, 'withHeaders')) {
            return $response->withHeaders([
                'X-RateLimit-Limit' => $limits['max'],
                'X-RateLimit-Remaining' => $this->limiter->remaining($key, $limits['max']),
            ]);
        }

        return $response;
    }

    private function resolveKey(Request $request, string $action): string
    {
        $identifier = $request->user()?->id ?? $request->ip();
        return "rate_limit:{$action}:{$identifier}";
    }

    private function getLimits(string $action): array
    {
        return match ($action) {
            'public_submit' => ['max' => 10, 'decay' => 60],      // 10 per minute per IP
            'ai_generate' => ['max' => 5, 'decay' => 60],         // 5 per minute per user
            'ai_modify' => ['max' => 10, 'decay' => 60],          // 10 per minute per user
            'import' => ['max' => 5, 'decay' => 300],             // 5 per 5 minutes per user
            'export' => ['max' => 10, 'decay' => 60],             // 10 per minute per user
            'auth' => ['max' => 5, 'decay' => 60],                // 5 per minute per IP
            default => ['max' => 60, 'decay' => 60],              // 60 per minute default
        };
    }
}
