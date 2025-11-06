# Auth Bridge for Laravel

`webgeniusmkt/auth-bridge-laravel` is a reusable bridge package for Laravel applications that authenticate and authorize via the centralized [Auth API service](https://github.com/webgeniusmkt/auth-api). It keeps a lightweight local user record in sync with the external identity provider while delegating all token handling, roles, and permissions to the Auth API.

## Requirements

- PHP 8.2+
- Laravel 10.x or 11.x
- Central Auth API v1 (Passport-based)

## Installation

```bash
composer require webgeniusmkt/auth-bridge-laravel
```

Publish the configuration (optional) and migrations, then migrate:

```bash
php artisan vendor:publish --tag=auth-bridge-config
php artisan vendor:publish --tag=auth-bridge-migrations
php artisan migrate
```

Publishing the migration adds the shared `external_*` columns (and `avatar_url` / `last_seen_at`) to your app's `users` table. These columns serve as the local cache of Auth API state (remote user UUID, active account, roles/permissions payloads, etc.), while also relaxing the default `name` / `email` columns to be nullable for SSO flows.

## Configure the Guard

Update `config/auth.php` with a bridge guard that uses your existing `users` provider:

```php
'guards' => [
    'api' => [
        'driver' => 'auth-bridge',
        'provider' => 'users',
        'cache_ttl' => 30,        // seconds (set 0 to disable caching)
        'cache_store' => null,    // defaults to the app cache store
    ],
],
```

Point any API routes that rely on the centralized tokens to this guard:

```php
Route::middleware(['auth:api'])->group(function () {
    Route::get('/me', fn () => request()->user());
});
```

The guard automatically:

1. Extracts the Bearer token (or `api_token` input/cookie fallback).
2. Calls the Auth API `/user` endpoint with forwarded `X-Account-ID`/`X-App-Key` headers.
3. Synchronizes the response to your `users` table.
4. Injects the hydrated local user into the request (`request()->user()`).

## User Model Setup

Add the provided trait to your `App\Models\User` so the JSON/Date casts are registered:

```php
use AuthBridge\Laravel\Concerns\HasAuthBridgeUser;

class User extends Authenticatable
{
    use HasAuthBridgeUser;
}
```

The bridge writes to the following columns (created by the published migration):

| Column            | Purpose |
| ----------------- | ------- |
| `external_user_id` | Remote user UUID (unique) |
| `avatar_url`      | Remote profile image URL (if provided) |
| `external_account_id` | Currently scoped account UUID |
| `external_accounts` | JSON accounts array from Auth API |
| `external_apps` | JSON apps array for conveniences |
| `external_status` | Remote user status enum |
| `external_payload` | Raw payload from `/user` endpoint (for debugging/caching) |
| `external_synced_at` | Timestamp of last sync |
| `last_seen_at` | Last activity timestamp reported by Auth API (nullable) |

Passwords are not used locally—the synchronizer stores a random hash on first sync so legacy features that require a `password` column continue to work without accepting credentials.

## Accessing Remote Context

Use the facade or injected `AuthBridgeContext` service to read the raw payload, enforce app-scoped roles, or permissions:

```php
use AuthBridge\Laravel\Facades\AuthBridge;

if (AuthBridge::hasPermission('documents.write')) {
    // user has permission within the current (account × app) scope
}
```

The service provider also registers two middleware aliases for quick policy checks:

| Middleware | Usage |
| ---------- | ----- |
| `auth-bridge.permission:<permission>[,<accountId>[,<appKey>]]` | Ensure the incoming request has the specified permission. Values `current`/`context` fall back to headers/payload. |
| `auth-bridge.role:<role>[,<accountId>[,<appKey>]]` | Ensure the user holds the given role. |

Example:

```php
Route::post('/accounts/{account}/reports', ReportController::class)
    ->middleware('auth-bridge.permission:reports.manage,route:account,current');
```

## Environment Variables

Override defaults via `.env` as needed:

```
AUTH_BRIDGE_BASE_URL=https://auth.example.com/api/v1
AUTH_BRIDGE_USER_ENDPOINT=/user
AUTH_BRIDGE_HTTP_TIMEOUT=5
AUTH_BRIDGE_HTTP_CONNECT_TIMEOUT=2
AUTH_BRIDGE_CACHE_TTL=30
AUTH_BRIDGE_CACHE_STORE=
AUTH_BRIDGE_ACCOUNT_HEADER=X-Account-ID
AUTH_BRIDGE_APP_HEADER=X-App-Key
# AUTH_BRIDGE_INPUT_KEY=api_token
# AUTH_BRIDGE_STORAGE_KEY=api_token
# AUTH_BRIDGE_EXTERNAL_ID_COLUMN=external_user_id
# AUTH_BRIDGE_ACCOUNT_ID_COLUMN=external_account_id
# AUTH_BRIDGE_ACCOUNT_IDS_COLUMN=external_accounts
# AUTH_BRIDGE_APP_IDS_COLUMN=external_apps
# AUTH_BRIDGE_STATUS_COLUMN=external_status
# AUTH_BRIDGE_PAYLOAD_COLUMN=external_payload
# AUTH_BRIDGE_SYNCED_AT_COLUMN=external_synced_at
# AUTH_BRIDGE_AVATAR_COLUMN=avatar_url
# AUTH_BRIDGE_LAST_SEEN_COLUMN=last_seen_at
```

## Why a Local `users` Table?

Each downstream app still needs a `users` table because:

- Relationships (orders, comments, etc.) expect a local `users.id` FK.
- You can join against local users without additional API calls.
- Additional per-app profile data can live alongside the shared Auth API snapshot.

The bridge migration + synchronizer keep that table aligned with the master Auth API record, so your apps remain thin while still benefiting from first-class Eloquent relations.

## Advanced Notes

- Remote payload caching defaults to 30 seconds; set `cache_ttl` to `0` to disable.
- All network errors bubble up as `401 Unauthorized`.
- The package assumes the Auth API returns a JSON response compatible with the project PRD. Adjust the `UserSynchronizer` if your payload deviates.

## Contributing

Issues and PRs are welcome. Please run `composer test` (Pest via Testbench) before opening a PR.
