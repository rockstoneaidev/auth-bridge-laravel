<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Support\Firebase;

use Firebase\JWT\JWK;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class JwksCache
{
    public function __construct(
        private readonly string $jwksUrl,
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
        private readonly int $cacheTtl = 3600,
    ) {
    }

    /**
     * Get public keys from JWKS, cached.
     *
     * Returns an array of kid => public key resource mappings suitable
     * for JWT signature verification.
     *
     * @return array<string, resource|string>  Key ID => OpenSSL public key resource or PEM string
     */
    public function getKeys(): array
    {
        return $this->cache->remember(
            'auth-bridge:firebase:jwks',
            $this->cacheTtl,
            fn () => $this->fetchAndParseKeys()
        );
    }

    /**
     * Fetch JWKS from Google and parse into usable public keys.
     *
     * @return array<string, resource|string>
     */
    private function fetchAndParseKeys(): array
    {
        $response = $this->http->get($this->jwksUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Firebase JWKS: '.$response->status());
        }

        $jwks = $response->json();

        if (! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            throw new RuntimeException('Invalid JWKS response format');
        }

        // Use firebase/php-jwt's JWK parser to convert JWKS to public keys
        return JWK::parseKeySet($jwks);
    }
}
