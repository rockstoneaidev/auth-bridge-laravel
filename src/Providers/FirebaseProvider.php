<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Providers;

use AuthBridge\Laravel\Contracts\AuthProviderInterface;
use AuthBridge\Laravel\Exceptions\ExpiredTokenException;
use AuthBridge\Laravel\Exceptions\InvalidTokenException;
use AuthBridge\Laravel\Support\Firebase\TokenVerifier;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class FirebaseProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly TokenVerifier $verifier,
        private readonly string $projectId,
    ) {
    }

    /**
     * Authenticate a Firebase ID token.
     *
     * Verifies the JWT signature and claims, then transforms Firebase-specific
     * claims into the normalized auth-bridge payload format.
     *
     * @param  string  $token  Firebase ID token
     * @param  array<string, string|null>  $headers  Context headers (unused for Firebase)
     * @return array<string, mixed>  Normalized user payload
     *
     * @throws UnauthorizedHttpException  When token verification fails
     */
    public function authenticate(string $token, array $headers = []): array
    {
        try {
            // Verify Firebase ID token (JWT) with project validation
            $claims = $this->verifier->verify($token, $this->projectId);

            // Transform Firebase claims to auth-bridge normalized format
            return $this->transformClaims($claims);
        } catch (InvalidTokenException|ExpiredTokenException $exception) {
            throw new UnauthorizedHttpException(
                'Bearer',
                'Firebase token verification failed: '.$exception->getMessage(),
                $exception
            );
        }
    }

    /**
     * Get cache key prefix for Firebase provider.
     *
     * Includes project ID to prevent cache collisions between different
     * Firebase projects in multi-tenant scenarios.
     *
     * @return string  Cache key prefix with project ID
     */
    public function getCacheKeyPrefix(): string
    {
        return "firebase:{$this->projectId}";
    }

    /**
     * Transform Firebase token claims to auth-bridge payload format.
     *
     * Maps Firebase-specific claims to the normalized user structure
     * expected by UserSynchronizer.
     *
     * @param  array<string, mixed>  $claims  Decoded Firebase token claims
     * @return array<string, mixed>  Normalized payload
     */
    private function transformClaims(array $claims): array
    {
        return [
            // Core user fields
            'id' => $claims['sub'], // Firebase uid → external_user_id
            'email' => $claims['email'] ?? null,
            'name' => $claims['name'] ?? $claims['email'] ?? null,
            'email_verified_at' => ($claims['email_verified'] ?? false)
                ? Carbon::now()->toDateTimeString()
                : null,
            'avatar_url' => $claims['picture'] ?? null,
            'status' => 'active', // Firebase users are active by definition

            // Firebase-specific metadata (stored in external_payload)
            'firebase_uid' => $claims['sub'],
            'firebase_auth_time' => $claims['auth_time'] ?? null,
            'firebase_sign_in_provider' => $claims['firebase']['sign_in_provider'] ?? null,
            'firebase_identities' => $claims['firebase']['identities'] ?? null,
        ];
    }
}
