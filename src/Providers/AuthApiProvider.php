<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Providers;

use AuthBridge\Laravel\Contracts\AuthProviderInterface;
use AuthBridge\Laravel\Http\AuthBridgeClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthApiProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly AuthBridgeClient $client,
    ) {
    }

    /**
     * Authenticate a token via the centralized Auth API.
     *
     * This delegates to the existing AuthBridgeClient which performs an HTTP
     * GET request to the Auth API's /user endpoint with the provided token
     * and context headers.
     *
     * @param  string  $token  Bearer token
     * @param  array<string, string|null>  $headers  Context headers (X-Account-ID, X-App-Key)
     * @return array<string, mixed>  User payload from Auth API
     *
     * @throws UnauthorizedHttpException  When Auth API rejects the token
     */
    public function authenticate(string $token, array $headers = []): array
    {
        try {
            $rawPayload = $this->client->fetchUser($token, $headers);

            // Preserve existing envelope extraction behavior
            // Some Auth API responses wrap data in a 'data' key
            return Arr::get($rawPayload, 'data', $rawPayload);
        } catch (RequestException $exception) {
            throw new UnauthorizedHttpException(
                'Bearer',
                'Auth API rejected the supplied token.',
                $exception
            );
        }
    }

    /**
     * Get cache key prefix for Auth API provider.
     *
     * @return string  Cache key prefix
     */
    public function getCacheKeyPrefix(): string
    {
        return 'auth-api';
    }
}
