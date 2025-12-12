# Firebase Authentication Provider Implementation Plan

## Goal

Add Firebase Authentication as a first-class, pluggable authentication provider to the `auth-bridge-laravel` package while preserving 100% backward compatibility with the existing Auth API integration.

### Background Context

Currently, all Laravel apps using `auth-bridge-laravel` authenticate exclusively via a centralized Auth API service. This implementation will maintain that capability while adding Firebase as an alternative provider, with:

- **One Firebase project per app per environment** (e.g., `docs-staging`, `docs-prod`)
- No cross-app SSO or shared Firebase projects
- Firebase as the default for new apps
- Zero breaking changes for existing Auth API apps

---

## User Review Required

> [!IMPORTANT]
> **Breaking Change Mitigation**: While the architecture introduces new abstractions (interfaces, provider classes), existing apps will continue functioning without any code changes. The default provider selection logic must be carefully tested to ensure backward compatibility.

> [!WARNING]
> **Security Critical**: Firebase JWT verification must validate ALL claims correctly:
> - Signature verification using Google's public keys (RS256)
> - Issuer (`iss`) must match `https://securetoken.google.com/{project_id}`
> - Audience (`aud`) must match the configured `FIREBASE_PROJECT_ID`
> - Expiration (`exp`) and issued-at (`iat`) with clock skew tolerance
> - Subject (`sub`) presence (Firebase uid)

> [!IMPORTANT]
> **Dependency Addition**: Implementation requires adding a JWT/JWK library for Firebase token verification. Recommend `firebase/php-jwt` or `web-token/jwt-framework` to avoid manual ASN.1 encoding.

---

## Proposed Changes

### Core Abstractions

#### [NEW] [Contracts/AuthProviderInterface.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Contracts/AuthProviderInterface.php)

**Purpose**: Define the contract that all authentication providers must implement.

```php
interface AuthProviderInterface
{
    /**
     * Authenticate a token and return normalized user payload.
     *
     * @param string $token Bearer token from request
     * @param array<string, string|null> $headers Context headers (X-Account-ID, X-App-Key)
     * @return array<string, mixed> Normalized user payload for UserSynchronizer
     * @throws UnauthorizedHttpException on authentication failure
     */
    public function authenticate(string $token, array $headers = []): array;
    
    /**
     * Get provider-specific cache key prefix for cache isolation.
     */
    public function getCacheKeyPrefix(): string;
}
```

**Key Points**:
- Returns normalized payload compatible with existing `UserSynchronizer`
- Throws `UnauthorizedHttpException` for consistency with current guard behavior
- Cache key prefix prevents collision between provider tokens

---

### Provider Implementations

#### [NEW] [Providers/AuthApiProvider.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Providers/AuthApiProvider.php)

**Purpose**: Wrap existing `AuthBridgeClient` logic into the new provider interface. This is a refactoring wrapper with zero behavioral changes.

```php
class AuthApiProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly AuthBridgeClient $client,
    ) {}
    
    public function authenticate(string $token, array $headers = []): array
    {
        try {
            $rawPayload = $this->client->fetchUser($token, $headers);
            
            // Preserve existing envelope extraction behavior
            return Arr::get($rawPayload, 'data', $rawPayload);
            
        } catch (RequestException $exception) {
            throw new UnauthorizedHttpException(
                'Bearer', 
                'Auth API rejected the supplied token.', 
                $exception
            );
        }
    }
    
    public function getCacheKeyPrefix(): string
    {
        return 'auth-api';
    }
}
```

#### [NEW] [Providers/FirebaseProvider.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Providers/FirebaseProvider.php)

**Purpose**: Implement Firebase ID token verification and transform claims to auth-bridge payload format.

```php
class FirebaseProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly TokenVerifier $verifier,
        private readonly string $projectId,
    ) {}
    
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
                'Firebase token verification failed: ' . $exception->getMessage(),
                $exception
            );
        }
    }
    
    private function transformClaims(array $claims): array
    {
        return [
            'id' => $claims['sub'],  // Firebase uid → external_user_id
            'email' => $claims['email'] ?? null,
            'name' => $claims['name'] ?? $claims['email'] ?? null,
            'email_verified_at' => ($claims['email_verified'] ?? false) 
                ? now()->toDateTimeString() 
                : null,
            'avatar_url' => $claims['picture'] ?? null,
            'status' => 'active',
            
            // Store Firebase-specific metadata in external_payload
            'firebase_uid' => $claims['sub'],
            'firebase_auth_time' => $claims['auth_time'] ?? null,
            'firebase_sign_in_provider' => $claims['firebase']['sign_in_provider'] ?? null,
        ];
    }
    
    public function getCacheKeyPrefix(): string
    {
        return "firebase:{$this->projectId}";
    }
}
```

**Transformation Mapping**:
- `sub` → `id` (used as `external_user_id`)
- `email` → `email`
- `name` / fallback `email` → `name`
- `email_verified` → `email_verified_at`
- `picture` → `avatar_url`
- Firebase-specific claims preserved in payload for debugging

---

### Firebase Support Classes

#### [NEW] [Support/Firebase/TokenVerifier.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Support/Firebase/TokenVerifier.php)

**Purpose**: Verify Firebase ID tokens using RS256 JWT signature verification and claim validation.

**Key Responsibilities**:
1. Decode JWT (header, payload, signature)
2. Fetch public key from JWKS cache using `kid` from header
3. Verify RS256 signature using OpenSSL
4. Validate standard claims:
   - `exp` (expiration with clock skew)
   - `iat` (issued-at with clock skew)
   - `iss` (issuer = `https://securetoken.google.com/{project_id}`)
   - `aud` (audience = `{project_id}`)
   - `sub` (subject/uid presence)

**Configuration**:
- `$issuerPrefix`: Default `https://securetoken.google.com/`
- `$clockSkew`: Default 60 seconds (tolerance for time drift)

**Exceptions**:
- `InvalidTokenException`: Signature fail, claim validation fail, wrong project
- `ExpiredTokenException`: Token expired beyond clock skew

#### [NEW] [Support/Firebase/JwksCache.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Support/Firebase/JwksCache.php)

**Purpose**: Fetch and cache Google's Firebase public keys (JWKS) to avoid repeated HTTP calls.

**Key Points**:
- Fetches from `https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com`
- Caches for 1 hour (configurable via `FIREBASE_JWKS_CACHE_TTL`)
- Converts JWK format to OpenSSL-compatible PEM format
- Returns array of `kid => resource` (public key resources)

**Implementation Note**: Use `firebase/php-jwt` library's built-in JWK handling or `web-token/jwt-framework` to avoid manual ASN.1 encoding complexity.

#### [NEW] [Exceptions/InvalidTokenException.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Exceptions/InvalidTokenException.php)

```php
class InvalidTokenException extends UnauthorizedHttpException
{
    public function __construct(string $message = 'Invalid token', \Throwable $previous = null)
    {
        parent::__construct('Bearer', $message, $previous);
    }
}
```

#### [NEW] [Exceptions/ExpiredTokenException.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Exceptions/ExpiredTokenException.php)

```php
class ExpiredTokenException extends UnauthorizedHttpException
{
    public function __construct(string $message = 'Token expired', \Throwable $previous = null)
    {
        parent::__construct('Bearer', $message, $previous);
    }
}
```

---

### Modified Core Components

#### [MODIFY] [Guards/AuthBridgeGuard.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/Guards/AuthBridgeGuard.php)

**Changes**:
1. Add `AuthProviderInterface` dependency injection in constructor
2. Replace direct `AuthBridgeClient` calls with `$this->provider->authenticate()`
3. Update `cacheKey()` method to use provider-specific prefix

**Modified Constructor**:
```php
public function __construct(
    private readonly string $name,
    private Request $request,
    private readonly AuthProviderInterface $provider,  // ← NEW
    private readonly UserSynchronizer $synchronizer,
    private readonly CacheRepository $cache,
    private readonly Dispatcher $events,
    private readonly array $config = [],
) {}
```

**Modified `user()` Method** (lines 42-93):
```php
public function user(): ?Authenticatable
{
    if (!is_null($this->user)) {
        return $this->user;
    }
    
    $token = $this->getTokenForRequest();
    if (!$token) {
        return null;
    }
    
    $headers = $this->resolveContextHeaders();
    $cacheKey = $this->cacheKey($token, $headers);
    $ttl = (int) ($this->config['cache_ttl'] ?? 30);
    
    if ($ttl > 0) {
        $payload = $this->cache->remember(
            $cacheKey,
            $ttl,
            fn() => $this->provider->authenticate($token, $headers)  // ← Changed
        );
    } else {
        $payload = $this->provider->authenticate($token, $headers);  // ← Changed
    }
    
    $context = [
        'account_id' => $headers[$this->config['headers']['account'] ?? 'X-Account-ID'] ?? null,
        'app_key' => $headers[$this->config['headers']['app'] ?? 'X-App-Key'] ?? null,
    ];
    
    $user = $this->synchronizer->sync($payload, $context);
    
    $this->setUser($user);
    $this->events->dispatch(new Authenticated($this->name, $user));
    $this->request->setUserResolver(fn() => $this->user);
    $this->request->attributes->set('auth-bridge.user', $payload);
    
    return $this->user;
}
```

**Modified `cacheKey()` Method** (lines 154-162):
```php
protected function cacheKey(string $token, array $headers = []): string
{
    $headerString = collect($headers)
        ->map(fn($value, $key) => "{$key}:{$value}")
        ->sort()
        ->implode(';');
    
    $prefix = $this->provider->getCacheKeyPrefix();  // ← NEW
    
    return "auth-bridge:{$prefix}:" . sha1($token . '|' . $headerString);
}
```

**Remove `fetchRemoteUser()` method** (lines 129-136) → Logic moved to providers

#### [MODIFY] [AuthBridgeServiceProvider.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/src/AuthBridgeServiceProvider.php)

**Changes**:
1. Add provider registration logic in `register()` method
2. Bind `AuthProviderInterface` based on config
3. Update guard factory to inject `AuthProviderInterface` instead of `AuthBridgeClient`

**Modified `register()` Method**:
```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__ . '/../config/auth-bridge.php', 'auth-bridge');
    
    // Bind the selected authentication provider
    $this->app->singleton(AuthProviderInterface::class, function (Container $app): AuthProviderInterface {
        $config = $app->make('config');
        $provider = $config->get('auth-bridge.provider', 'firebase');
        
        return match ($provider) {
            'auth_api' => $this->createAuthApiProvider($app, $config),
            'firebase' => $this->createFirebaseProvider($app, $config),
            default => throw new InvalidArgumentException("Unknown auth provider: {$provider}"),
        };
    });
    
    // Keep AuthBridgeClient binding for backward compatibility (used by commands)
    $this->app->singleton(AuthBridgeClient::class, function (Container $app): AuthBridgeClient {
        $config = $app->make('config');
        return new AuthBridgeClient(
            baseUrl: rtrim((string) $config->get('auth-bridge.auth_api.base_url'), '/'),
            userEndpoint: '/' . ltrim((string) $config->get('auth-bridge.auth_api.user_endpoint'), '/'),
            http: $app->make(HttpFactory::class),
            httpConfig: $config->get('auth-bridge.http', []),
        );
    });
    
    $this->app->singleton(AuthBridgeContext::class, fn($app) => new AuthBridgeContext($app['request']));
}

private function createAuthApiProvider(Container $app, ConfigRepository $config): AuthApiProvider
{
    return new AuthApiProvider($app->make(AuthBridgeClient::class));
}

private function createFirebaseProvider(Container $app, ConfigRepository $config): FirebaseProvider
{
    $projectId = $config->get('auth-bridge.firebase.project_id');
    
    if (!$projectId) {
        throw new RuntimeException('FIREBASE_PROJECT_ID is required when using firebase provider');
    }
    
    $jwksCache = new JwksCache(
        jwksUrl: $config->get('auth-bridge.firebase.jwks_url'),
        cache: $app->make('cache')->store($config->get('auth-bridge.cache.store')),
        http: $app->make(HttpFactory::class),
        cacheTtl: (int) $config->get('auth-bridge.firebase.jwks_cache_ttl', 3600),
    );
    
    $verifier = new TokenVerifier(
        jwksCache: $jwksCache,
        issuerPrefix: $config->get('auth-bridge.firebase.issuer_prefix'),
        clockSkew: (int) $config->get('auth-bridge.firebase.clock_skew_seconds', 60),
    );
    
    return new FirebaseProvider($verifier, $projectId);
}
```

**Modified `registerGuard()` Method** (lines 104-148):

Change line 137 to inject `AuthProviderInterface`:
```php
return new AuthBridgeGuard(
    name: $name,
    request: $app['request'],
    provider: $app->make(AuthProviderInterface::class),  // ← Changed from client
    synchronizer: $synchronizer,
    cache: $cache,
    events: $events,
    config: [
        'headers' => $configRepository->get('auth-bridge.headers', []),
        'cache_ttl' => $config['cache_ttl'] ?? $configRepository->get('auth-bridge.cache.ttl', 30),
        'input_key' => $config['input_key'] ?? $configRepository->get('auth-bridge.guard.input_key'),
        'storage_key' => $config['storage_key'] ?? $configRepository->get('auth-bridge.guard.storage_key'),
    ],
);
```

---

### Configuration

#### [MODIFY] [config/auth-bridge.php](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/config/auth-bridge.php)

Add provider selection and Firebase configuration sections at the top (after line 12):

```php
/*
|--------------------------------------------------------------------------
| Authentication Provider
|--------------------------------------------------------------------------
|
| Determines which provider handles token verification and user context.
| Supported: 'auth_api', 'firebase'
|
| - auth_api: Centralized Auth API with OAuth2 (legacy)
| - firebase: Firebase Authentication (default for new apps)
|
*/

'provider' => env('AUTH_BRIDGE_PROVIDER', 'firebase'),

/*
|--------------------------------------------------------------------------
| Auth API Configuration
|--------------------------------------------------------------------------
|
| Settings for the centralized Auth API provider (when provider=auth_api).
|
*/

'auth_api' => [
    'base_url' => env('AUTH_BRIDGE_BASE_URL'),
    'public_url' => env('AUTH_BRIDGE_PUBLIC_URL', env('AUTH_BRIDGE_BASE_URL')),
    'user_endpoint' => env('AUTH_BRIDGE_USER_ENDPOINT', '/user'),
    'internal_bootstrap_path' => env('AUTH_BRIDGE_BOOTSTRAP_PATH', '/internal/apps/bootstrap'),
    'app_lookup_path' => env('AUTH_BRIDGE_APP_LOOKUP_PATH', '/apps'),
    'default_redirect_suffix' => env('AUTH_BRIDGE_DEFAULT_REDIRECT_SUFFIX', '/oauth/callback'),
],

/*
|--------------------------------------------------------------------------
| Firebase Authentication Configuration
|--------------------------------------------------------------------------
|
| Settings for Firebase provider (when provider=firebase).
|
| IMPORTANT: Use one Firebase project per app per environment.
| Example: docs-staging, docs-prod (NOT shared across apps)
|
*/

'firebase' => [
    // Firebase project ID (e.g., myapp-staging, myapp-prod)
    'project_id' => env('FIREBASE_PROJECT_ID'),
    
    // Google's JWKS endpoint for Firebase public keys
    'jwks_url' => env(
        'FIREBASE_JWKS_URL',
        'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com'
    ),
    
    // Expected token issuer prefix
    'issuer_prefix' => env('FIREBASE_ISSUER_PREFIX', 'https://securetoken.google.com/'),
    
    // Cache duration for JWKS public keys (seconds)
    'jwks_cache_ttl' => (int) env('FIREBASE_JWKS_CACHE_TTL', 3600),
    
    // Clock skew tolerance for exp/iat validation (seconds)
    'clock_skew_seconds' => (int) env('FIREBASE_CLOCK_SKEW', 60),
],
```

**Deprecate top-level keys** (add comments for backward compatibility):
- Lines 48, 65, 77, 89-91 → Move under `auth_api` array, keep as fallbacks

---

### Dependencies

#### [MODIFY] [composer.json](file:///Users/matsbergsten/Utveckling/www/Apps/auth-bridge-laravel/composer.json)

Add JWT library dependency:

```json
"require": {
    "php": "^8.4",
    "illuminate/contracts": "^12.0",
    "illuminate/support": "^12.0",
    "firebase/php-jwt": "^6.10"
}
```

**Rationale**: `firebase/php-jwt` provides:
- JWT decoding and verification
- JWK to PEM conversion
- RS256 signature validation
- Well-tested, Firebase-recommended library

---

### Laravel App Template Updates

#### [MODIFY] `.env.example` (laravel-app-template)

```env
# Authentication Provider
# Options: firebase (default) | auth_api (legacy)
AUTH_BRIDGE_PROVIDER=firebase

# App Identification
APP_KEY_SLUG=myapp
APP_NAME=MyApp
APP_URL=http://localhost:8000

# ===== Firebase Configuration (when AUTH_BRIDGE_PROVIDER=firebase) =====
FIREBASE_PROJECT_ID=myapp-staging
FIREBASE_JWKS_URL=https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com
FIREBASE_ISSUER_PREFIX=https://securetoken.google.com/
FIREBASE_JWKS_CACHE_TTL=3600
FIREBASE_CLOCK_SKEW=60

# ===== Auth API Configuration (when AUTH_BRIDGE_PROVIDER=auth_api) =====
# AUTH_BRIDGE_BASE_URL=https://auth.example.com/api/v1
# AUTH_BRIDGE_PUBLIC_URL=https://auth.example.com
# OAUTH_CLIENT_ID=
# OAUTH_CLIENT_SECRET=

# Shared Settings
AUTH_BRIDGE_CACHE_TTL=30
AUTH_BRIDGE_ACCOUNT_HEADER=X-Account-ID
AUTH_BRIDGE_APP_HEADER=X-App-Key
```

#### [MODIFY] `install.sh` (laravel-app-template)

Add provider selection prompt after app name input:

```bash
# ... existing code ...

echo ""
echo "Select authentication provider:"
echo "  1) Firebase (recommended for new apps)"
echo "  2) Auth API (legacy/existing infrastructure)"
read -p "Choice [1]: " provider_choice
provider_choice=${provider_choice:-1}

if [ "$provider_choice" == "1" ]; then
    echo "AUTH_BRIDGE_PROVIDER=firebase" >> .env
    
    read -p "Enter Firebase Project ID (e.g., ${APP_KEY_SLUG}-staging): " firebase_project
    firebase_project=${firebase_project:-"${APP_KEY_SLUG}-staging"}
    
    sed -i '' "s/FIREBASE_PROJECT_ID=.*/FIREBASE_PROJECT_ID=${firebase_project}/" .env
    
    echo "✓ Firebase provider configured"
    echo "  Next steps:"
    echo "  1. Create Firebase project: https://console.firebase.google.com"
    echo "  2. Enable Authentication → Email/Password"
    echo "  3. Update FIREBASE_PROJECT_ID in .env if needed"
    
elif [ "$provider_choice" == "2" ]; then
    echo "AUTH_BRIDGE_PROVIDER=auth_api" >> .env
    
    read -p "Enter Auth API Base URL: " auth_base_url
    sed -i '' "s|# AUTH_BRIDGE_BASE_URL=.*|AUTH_BRIDGE_BASE_URL=${auth_base_url}|" .env
    
    echo "✓ Auth API provider configured"
    echo "  Contact admin to create OAuth client and update .env"
fi

# ... rest of existing install script ...
```

#### [MODIFY] `README.md` (laravel-app-template)

Add authentication provider section:

```markdown
## Authentication

This template supports two authentication providers:

### Firebase Authentication (Default)

**Recommended for new applications**

1. Create a Firebase project at [Firebase Console](https://console.firebase.google.com)
2. Enable Authentication → Sign-in methods → Email/Password
3. Configure `.env`:
   ```env
   AUTH_BRIDGE_PROVIDER=firebase
   FIREBASE_PROJECT_ID=your-project-id
   ```
4. Use Firebase SDK in your frontend to obtain ID tokens
5. Send tokens as `Authorization: Bearer {token}` to Laravel API

**Per-Environment Setup**:
- Create separate Firebase projects for staging/production
- Example: `myapp-staging`, `myapp-prod`
- Update `FIREBASE_PROJECT_ID` per environment

### Auth API (Legacy)

**For apps using existing centralized Auth API**

1. Contact infrastructure admin to create OAuth client
2. Configure `.env`:
   ```env
   AUTH_BRIDGE_PROVIDER=auth_api
   AUTH_BRIDGE_BASE_URL=https://auth.example.com/api/v1
   OAUTH_CLIENT_ID=your-client-id
   OAUTH_CLIENT_SECRET=your-client-secret
   ```
3. Implement OAuth authorization code flow

### Migration from Auth API to Firebase

Existing apps can migrate gradually:
1. Create Firebase project for staging environment
2. Deploy with `AUTH_BRIDGE_PROVIDER=firebase`
3. Test thoroughly
4. Migrate production when ready
```

---

## Verification Plan

### Automated Tests

#### Unit Tests

**`tests/Unit/Providers/FirebaseProviderTest.php`**:
- ✓ Transforms Firebase claims correctly
- ✓ Throws exception for invalid token
- ✓ Throws exception for expired token
- ✓ Returns correct cache key prefix

**`tests/Unit/Providers/AuthApiProviderTest.php`**:
- ✓ Delegates to AuthBridgeClient
- ✓ Extracts data envelope
- ✓ Throws exception on request failure
- ✓ Returns correct cache key prefix

**`tests/Unit/Support/Firebase/TokenVerifierTest.php`**:
- ✓ Verifies valid token signature
- ✓ Validates issuer claim
- ✓ Validates audience claim
- ✓ Validates expiration with clock skew
- ✓ Rejects wrong project ID
- ✓ Rejects expired tokens
- ✓ Rejects tokens with invalid signature

**`tests/Unit/Support/Firebase/JwksCacheTest.php`**:
- ✓ Fetches JWKS from Google
- ✓ Caches keys for TTL duration
- ✓ Converts JWK to PEM format
- ✓ Handles fetch failures gracefully

**`tests/Unit/AuthBridgeServiceProviderTest.php`**:
- ✓ Binds FirebaseProvider when `provider=firebase`
- ✓ Binds AuthApiProvider when `provider=auth_api`
- ✓ Throws exception for unknown provider
- ✓ Throws exception when Firebase project ID missing

#### Integration Tests

**`tests/Feature/Guards/AuthBridgeGuardTest.php`**:
- ✓ Authenticates user with Firebase provider (mock token verification)
- ✓ Authenticates user with Auth API provider (mock HTTP client)
- ✓ Caches user payload with provider-specific key
- ✓ Provisions new user via Firebase token
- ✓ Updates existing user via Firebase token
- ✓ Returns 401 for invalid Firebase token
- ✓ Returns 401 for expired Firebase token
- ✓ Returns 401 for wrong Firebase project

**Test Commands**:
```bash
# Unit tests only
vendor/bin/pest --filter=Unit

# Integration tests only
vendor/bin/pest --filter=Feature

# All tests
vendor/bin/pest

# Coverage report
vendor/bin/pest --coverage --min=80
```

### Manual Verification

**Scenario 1: New Firebase App**
1. Create fresh Laravel app from template
2. Select Firebase during `install.sh`
3. Create Firebase project, enable auth
4. Use Firebase SDK to obtain ID token
5. Call Laravel API with token
6. Verify user provisioned in `users` table
7. Verify `external_user_id` = Firebase uid

**Scenario 2: Existing Auth API App**
1. Deploy existing app without changes
2. Verify authentication still works
3. Check `.env` has `AUTH_BRIDGE_PROVIDER=auth_api` (or unset)
4. Confirm OAuth flow unchanged
5. Verify user sync logic unchanged

**Scenario 3: Migration Testing**
1. Deploy Auth API app to staging
2. Create Firebase project
3. Update `.env`: `AUTH_BRIDGE_PROVIDER=firebase`
4. Deploy again
5. Authenticate with Firebase token
6. Verify same users table, different `external_user_id`

**Scenario 4: Error Handling**
1. Send expired Firebase token → 401 with clear message
2. Send token from wrong project → 401 with "Invalid audience" message
3. Send malformed token → 401 with "Invalid token" message
4. Remove `FIREBASE_PROJECT_ID` → 500 with config error

---

## Migration Strategy

### Phase 1: Package Updates (No Breaking Changes)

1. Add new provider abstraction and Firebase implementation
2. Update config with backward-compatible defaults
3. Tag release as `v2.0.0` (semantic versioning)
4. Update documentation with migration guide

**Existing apps**: No action required, continue using Auth API

### Phase 2: Template Updates

1. Update `laravel-app-template` to default to Firebase
2. Update `install.sh` with provider selection
3. Tag template release as `v2.0.0`

**New apps**: Get Firebase by default with opt-in to Auth API

### Phase 3: Gradual Migration (Optional)

**For apps wanting to migrate**:
1. Create Firebase project per environment
2. Test in staging first
3. Deploy production when ready
4. No data migration needed (separate `external_user_id` column)

**Timeline**: No forced migration, apps can stay on Auth API indefinitely

---

## Rollback Plan

**If issues discovered post-release**:

1. Existing Auth API apps unaffected (no code changes)
2. New apps can switch back via `.env`:
   ```env
   AUTH_BRIDGE_PROVIDER=auth_api
   # ... configure Auth API credentials
   ```
3. Emergency patch: Change config default from `firebase` to `auth_api`
4. Document breaking changes in release notes

---

## Security Considerations

### Firebase Token Verification

> [!CAUTION]
> **Critical Security Requirements**:
> - MUST verify RSA signature using Google's public keys
> - MUST validate `iss` claim matches `https://securetoken.google.com/{project_id}`
> - MUST validate `aud` claim exactly matches configured `FIREBASE_PROJECT_ID`
> - MUST check `exp` (expiration) with reasonable clock skew (60s)
> - MUST check `iat` (issued-at) to prevent future-dated tokens
> - MUST verify `sub` (uid) is present and non-empty

**Why this matters**: Failing to validate any claim could allow:
- Tokens from different Firebase projects (cross-app impersonation)
- Expired tokens (replay attacks)
- Tampered tokens (signature bypass)

### JWKS Caching

- Cache Google's public keys for 1 hour (default)
- Keys rarely rotate (weeks/months)
- On cache miss, fetch fresh keys immediately
- No manual key rotation needed

### Secrets Management

**Never log or expose**:
- Full Bearer tokens (only log prefix: first 10 chars)
- JWKS private keys (we only use public keys)
- OAuth client secrets (Auth API only)

**Safe to log**:
- Firebase project ID
- External user ID (UID)
- Token validation errors
- Provider selection

### Rate Limiting

Recommend adding rate limiting middleware:
```php
Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/api/user', ...);
});
```

Firebase tokens are valid for 1 hour, caching mitigates repeated verifications.

---

## Logging & Observability

### Success Events (Info Level)

```php
Log::info('User authenticated successfully', [
    'provider' => 'firebase',
    'external_user_id' => $payload['id'],
    'app_key' => $context['app_key'],
    'request_id' => request()->header('X-Request-ID'),
    'was_provisioned' => $wasNewUser,
]);
```

### Failure Events (Warning Level)

```php
Log::warning('Authentication failed', [
    'provider' => 'firebase',
    'error' => $exception->getMessage(),
    'error_type' => get_class($exception),
    'project_id' => $projectId,
    'token_prefix' => substr($token, 0, 10) . '...',
    'request_id' => request()->header('X-Request-ID'),
]);
```

### Configuration Errors (Error Level)

```php
Log::error('Provider configuration invalid', [
    'provider' => 'firebase',
    'missing_config' => 'FIREBASE_PROJECT_ID',
    'configured_provider' => config('auth-bridge.provider'),
]);
```

### Metrics to Track

**Application Performance Monitoring** (APM):
- Auth success/failure rate by provider
- Token verification latency (p50, p95, p99)
- JWKS cache hit ratio
- New user provisioning rate

**Alerting Thresholds**:
- Auth failure rate > 5% → Alert
- JWKS fetch failures → Alert (Google outage)
- Token verification > 500ms p95 → Warning
- Config errors → Critical alert

**Correlation Fields**:
- `request_id`: Trace request through system
- `external_user_id`: User-centric debugging
- `provider`: Separate Firebase vs Auth API metrics
- `app_key`: Per-app authentication patterns

---

## Timeline & Dependencies

### Prerequisites
- ✓ Existing codebase analysis complete
- ✓ Architecture design approved
- ⏳ Dependency selection (`firebase/php-jwt` vs alternatives)

### Implementation Phases

**Phase 1: Core Abstraction** (1-2 days)
- Create `AuthProviderInterface`
- Create `AuthApiProvider` (refactor existing logic)
- Update `AuthBridgeGuard` to use provider
- Update `AuthBridgeServiceProvider` registration

**Phase 2: Firebase Implementation** (2-3 days)
- Add `firebase/php-jwt` dependency
- Implement `FirebaseProvider`
- Implement `TokenVerifier` with claim validation
- Implement `JwksCache` with Google JWKS fetching
- Create custom exceptions

**Phase 3: Configuration & Documentation** (1 day)
- Update `config/auth-bridge.php`
- Create migration guide
- Update package README
- Add code examples

**Phase 4: Testing** (2-3 days)
- Write unit tests (providers, verifier, cache)
- Write integration tests (guard, service provider)
- Manual testing with real Firebase tokens
- Backward compatibility testing with Auth API

**Phase 5: Template Updates** (1 day)
- Update `laravel-app-template` `.env.example`
- Update `install.sh` with provider selection
- Update template README
- Test fresh app installation

**Total Estimated Time**: 7-10 days

### Release Plan
1. Merge to `main` branch
2. Tag `v2.0.0` in auth-bridge-laravel
3. Tag `v2.0.0` in laravel-app-template
4. Publish release notes with migration guide
5. Update documentation site (if applicable)
6. Notify existing users via changelog

---

## Open Questions

1. **JWT Library Choice**: Use `firebase/php-jwt` (simple, Firebase-recommended) or `web-token/jwt-framework` (more features, heavier)?
   - **Recommendation**: `firebase/php-jwt` for simplicity and Firebase ecosystem alignment

2. **MFA Handling**: Firebase handles MFA entirely (TOTP via Firebase SDK). Should we expose MFA status in payload?
   - **Decision Needed**: Add `mfa_enabled` field to transformed payload?

3. **Custom Claims**: Should we support Firebase custom claims in the payload transformation?
   - **Decision Needed**: Pass through all custom claims or filter to specific ones?

4. **Token Refresh**: Firebase tokens expire after 1 hour. Should package provide refresh token handling?
   - **Recommendation**: No, keep stateless. Client SDK handles refresh, sends new token.

5. **Backward Compatibility Default**: Should default provider be `firebase` or `auth_api` in v2.0.0?
   - **Current Plan**: `firebase` for new apps, but existing apps unaffected (no ENV change)

---

## Success Criteria

✅ **Functional Requirements**:
- [ ] Firebase tokens verified correctly with all claim validation
- [ ] Auth API integration unchanged (zero regression)
- [ ] JIT user provisioning works for both providers
- [ ] Provider switching via ENV works seamlessly
- [ ] Cache isolation between providers

✅ **Quality Requirements**:
- [ ] Unit test coverage ≥ 80%
- [ ] Integration tests cover both providers
- [ ] No breaking changes for existing apps
- [ ] Documentation complete with examples

✅ **Security Requirements**:
- [ ] All JWT claims validated per Firebase spec
- [ ] Project ID validation prevents cross-app tokens
- [ ] No secrets logged
- [ ] Error messages don't leak sensitive info

✅ **Performance Requirements**:
- [ ] JWKS cached (not fetched per request)
- [ ] Token verification < 50ms p95
- [ ] Cache hit ratio > 95% for repeat requests

---

_Document created: 2025-12-12_  
_Last updated: 2025-12-12_  
_Status: Ready for implementation_
