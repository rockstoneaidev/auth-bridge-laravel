<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Contracts;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

interface AuthProviderInterface
{
    /**
     * Authenticate a token and return normalized user payload.
     *
     * This method verifies the provided token using provider-specific logic
     * (e.g., Auth API HTTP call, Firebase JWT verification) and returns a
     * normalized payload compatible with the UserSynchronizer.
     *
     * @param  string  $token  Bearer token from request
     * @param  array<string, string|null>  $headers  Context headers (X-Account-ID, X-App-Key)
     * @return array<string, mixed>  Normalized user payload for synchronization
     *
     * @throws UnauthorizedHttpException  When authentication fails
     */
    public function authenticate(string $token, array $headers = []): array;

    /**
     * Get provider-specific cache key prefix for cache isolation.
     *
     * This prefix is used to build cache keys that are unique to each provider,
     * preventing cache collisions between different authentication providers.
     *
     * @return string  Cache key prefix (e.g., 'auth-api', 'firebase:project-id')
     */
    public function getCacheKeyPrefix(): string;
}
