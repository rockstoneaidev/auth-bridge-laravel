# Auth Bridge for Laravel

`rockstoneaidev/auth-bridge-laravel` is a reusable bridge package for Laravel applications that authenticate and authorize via **Firebase Authentication** (recommended) or the centralized [Auth API service](https://github.com/rockstoneaidev/auth-api) (legacy).

It keeps a lightweight local user record in sync with the external identity provider while delegating all token handling, roles, and permissions to the configured provider.

## Requirements

- PHP 8.4+
- Laravel 12.x
- Firebase Project (for Firebase provider) OR Central Auth API v1 (for Auth API provider)

## Installation

```bash
composer require rockstoneaidev/auth-bridge-laravel
```

Publish the configuration (optional) and migrations, then migrate:

```bash
php artisan vendor:publish --tag=auth-bridge-config
php artisan vendor:publish --tag=auth-bridge-migrations
php artisan migrate
```

Publishing the migration adds the shared `external_*` columns (and `avatar_url` / `last_seen_at`) to your app's `users` table. These columns serve as the local cache of the remote identity (Firebase UID or Auth API UUID).

## Configuration

### 1. Select Provider

Update `.env` to select your authentication provider:

```env
# Options: firebase (default/recommended) | auth_api (legacy)
AUTH_BRIDGE_PROVIDER=firebase
```

### 2. Configure Provider

#### Option A: Firebase (Default)

Configure your Firebase project details. Use a separate Firebase project for each environment (e.g., `myapp-staging`, `myapp-prod`).

```env
FIREBASE_PROJECT_ID=my-app-staging
# Optional: Override defaults
# FIREBASE_JWKS_URL=...
# FIREBASE_ISSUER_PREFIX=...
```

The bridge verifies Firebase ID tokens (JWT) using Google's public keys and maps claims to your local `users` table.

#### Option B: Auth API (Legacy)

For apps using the existing centralized Auth API infrastructure:

```env
AUTH_BRIDGE_PROVIDER=auth_api
AUTH_BRIDGE_BASE_URL=https://auth.example.com/api/v1
AUTH_BRIDGE_PUBLIC_URL=https://auth.example.com
```

## Configure the Guard

Update `config/auth.php` with a bridge guard that uses your existing `users` provider:

```php
'guards' => [
    'api' => [
        'driver' => 'auth-bridge',
        'provider' => 'users',
        'cache_ttl' => 30,        // seconds (set 0 to disable caching)
    ],
],
```

Point any API routes that rely on remote tokens to this guard:

```php
Route::middleware(['auth:api'])->group(function () {
    Route::get('/me', fn () => request()->user());
});
```

The guard automatically:

1. Extracts the Bearer token.
2. Authenticates via the configured provider (Firebase or Auth API).
3. Synchronizes the identity to your local `users` table.
4. Injects the hydrated local user into the request (`request()->user()`)

## User Model Setup

Add the provided trait to your `App\Models\User`:

```php
use AuthBridge\Laravel\Concerns\HasAuthBridgeUser;

class User extends Authenticatable
{
    use HasAuthBridgeUser;
}
```

## Onboarding a New App

### With Firebase (Recommended)

1. Create a Firebase project at [Firebase Console](https://console.firebase.google.com).
2. Enable Authentication (Email/Password, Google, etc.).
3. Set `AUTH_BRIDGE_PROVIDER=firebase` and `FIREBASE_PROJECT_ID=...` in `.env`.
4. Use the Firebase JS SDK in your frontend to obtain an ID token.
5. Send the token as `Authorization: Bearer <token>` to your Laravel API.

### With Auth API (Legacy)

Run the onboarding command to register your app and scaffold the OAuth client:

```bash
php artisan auth-bridge:onboard \
  --app-key=docs \
  --app-name="Docs" \
  --redirect=${APP_URL}/oauth/callback \
  --bootstrap-token=${AUTH_API_BOOTSTRAP_TOKEN}
```

## Accessing Remote Context

Use the facade or injected `AuthBridgeContext` service to read the raw payload or check permissions:

```php
use AuthBridge\Laravel\Facades\AuthBridge;

if (AuthBridge::hasPermission('documents.write')) {
    // user has permission
}
```

## Contributing

Issues and PRs are welcome. Please run `composer test` (Pest via Testbench) before opening a PR.
