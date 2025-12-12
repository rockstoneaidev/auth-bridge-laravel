<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Support\Firebase;

use AuthBridge\Laravel\Exceptions\ExpiredTokenException;
use AuthBridge\Laravel\Exceptions\InvalidTokenException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

class TokenVerifier
{
    public function __construct(
        private readonly JwksCache $jwksCache,
        private readonly string $issuerPrefix,
        private readonly int $clockSkew = 60,
    ) {
    }

    /**
     * Verify Firebase ID token and return decoded claims.
     *
     * Validates JWT signature using Google's public keys and checks all
     * standard Firebase claims (iss, aud, exp, iat, sub).
     *
     * @param  string  $token  Firebase ID token (JWT)
     * @param  string  $projectId  Expected Firebase project ID
     * @return array<string, mixed>  Decoded token claims
     *
     * @throws InvalidTokenException  For invalid tokens (signature, claims, format)
     * @throws ExpiredTokenException  For expired tokens
     */
    public function verify(string $token, string $projectId): array
    {
        // Set leeway for clock skew tolerance
        JWT::$leeway = $this->clockSkew;

        try {
            // Decode the header to get the kid (key ID)
            $tks = explode('.', $token);
            if (count($tks) !== 3) {
                throw new InvalidTokenException('Invalid token format');
            }

            $headb64 = $tks[0];
            $header = json_decode(JWT::urlsafeB64Decode($headb64), true);

            if (! isset($header['kid'])) {
                throw new InvalidTokenException('Token missing kid in header');
            }

            // Get the public key for this kid
            $keys = $this->jwksCache->getKeys();

            if (! isset($keys[$header['kid']])) {
                throw new InvalidTokenException('Token signed with unknown kid: '.$header['kid']);
            }

            // Verify the token using the public key
            // firebase/php-jwt handles signature verification and basic claim validation
            $decoded = JWT::decode($token, $keys);

            // Convert stdClass to array for easier manipulation
            $payload = json_decode(json_encode($decoded), true);

            // Validate Firebase-specific claims
            $this->validateClaims($payload, $projectId);

            return $payload;
        } catch (ExpiredTokenException $e) {
            // Re-throw our custom exception
            throw $e;
        } catch (UnexpectedValueException $e) {
            // firebase/php-jwt throws UnexpectedValueException for expired tokens
            if (str_contains($e->getMessage(), 'Expired token')) {
                throw new ExpiredTokenException('Token has expired');
            }

            throw new InvalidTokenException('Token verification failed: '.$e->getMessage(), $e);
        } catch (\Exception $e) {
            throw new InvalidTokenException('Token verification failed: '.$e->getMessage(), $e);
        }
    }

    /**
     * Validate Firebase-specific claims.
     *
     * @param  array<string, mixed>  $payload  Decoded token payload
     * @param  string  $projectId  Expected Firebase project ID
     *
     * @throws InvalidTokenException  When claims are invalid
     * @throws ExpiredTokenException  When token is expired
     */
    private function validateClaims(array $payload, string $projectId): void
    {
        $now = time();

        // Validate expiration (with clock skew)
        if (! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            throw new InvalidTokenException('Token missing exp claim');
        }

        if ($payload['exp'] < ($now - $this->clockSkew)) {
            throw new ExpiredTokenException('Token has expired');
        }

        // Validate issued-at (prevent future-dated tokens)
        if (! $payload['iat']) {
            throw new InvalidTokenException('Token missing iat claim');
        }

        if ($payload['iat'] > ($now + $this->clockSkew)) {
            throw new InvalidTokenException('Token issued in the future');
        }

        // Validate issuer (must be Firebase issuer + project ID)
        $expectedIssuer = $this->issuerPrefix.$projectId;
        if (($payload['iss'] ?? null) !== $expectedIssuer) {
            throw new InvalidTokenException("Invalid issuer. Expected: {$expectedIssuer}");
        }

        // Validate audience (must match project ID)
        if (($payload['aud'] ?? null) !== $projectId) {
            throw new InvalidTokenException("Invalid audience. Expected: {$projectId}");
        }

        // Validate subject (uid must be present)
        if (empty($payload['sub'])) {
            throw new InvalidTokenException('Token missing subject (uid)');
        }
    }
}
