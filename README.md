# Auth Bridge for Laravel

`webgeniusmkt/auth-bridge-laravel` is a reusable bridge package for Laravel applications that authenticate and authorize via the centralized [Auth API service](https://github.com/webgeniusmkt/auth-api). It keeps a lightweight local user record in sync with the external identity provider while delegating all token handling, roles, and permissions to the Auth API.

## Requirements

- PHP 8.4+
- Laravel 12.x
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
AUTH_BRIDGE_BOOTSTRAP_PATH=/internal/apps/bootstrap
AUTH_BRIDGE_DEFAULT_REDIRECT_SUFFIX=/oauth/callback
# AUTH_BRIDGE_INPUT_KEY=api_token
# AUTH_BRIDGE_STORAGE_KEY=api_token
# AUTH_BRIDGE_CHECK_TOKEN=
# APP_KEY_SLUG=myapp
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

## Onboarding a New Laravel App

Starting with this release, the package ships an opinionated onboarding flow that turns a fresh Laravel app into an Auth Bridge-ready app with a single Artisan command.

### Quick start: `auth-bridge:onboard`

```bash
php artisan auth-bridge:onboard \
  --app-key=docs \
  --app-name="Docs" \
  --redirect=${APP_URL:-http://localhost:8000}/oauth/callback \
  --bootstrap-token=${AUTH_API_BOOTSTRAP_TOKEN} \
  --accounts=1234-uuid-here
```

What the command does:

1. Publishes the bridge config/migrations (idempotent) and runs `php artisan migrate`.
2. Calls the Auth API internal bootstrap endpoint to create/link the OAuth client + application row (when `--bootstrap-token` is provided).
3. Writes/updates the required `.env` keys (`APP_KEY_SLUG`, `AUTH_BRIDGE_*`, `OAUTH_CLIENT_*`).
4. Scaffolds a minimal OAuth controller, routes, and middleware for browser flows.
5. Executes `auth-bridge:check` to hit `/health` and, if a token is supplied, `/user`.

`--dry` prints the plan without touching disk or calling remote services. Supply `--client-id/--client-secret` to skip the Auth API bootstrap step.

### Common flows

| Scenario | Command |
| --- | --- |
| Local dev bootstrap (create client + scaffold) | `php artisan auth-bridge:onboard --app-key=docs --app-name="Docs" --auth-base=https://auth.example.com/api/v1 --bootstrap-token=$ADMIN_TOKEN --accounts=123` |
| CI/CD first deploy | `php artisan auth-bridge:onboard --app-key=docs --app-name="Docs" --redirect=https://docs.example.com/oauth/callback --bootstrap-token=$AUTH_API_BOOTSTRAP_TOKEN --accounts=123` |
| Already have OAuth client | `php artisan auth-bridge:onboard --app-key=docs --client-id=abc --client-secret=shhh --redirect=https://docs.example.com/oauth/callback` |
| Preview only | `php artisan auth-bridge:onboard --app-key=docs --dry` |

### Supporting commands

| Command | Purpose |
| --- | --- |
| `auth-bridge:install` | Publish config + migrations and run `migrate`. Safe to re-run. |
| `auth-bridge:bootstrap-app` | Direct call to `AUTH_BRIDGE_BOOTSTRAP_PATH` to create/link the OAuth client; prints the returned client id/secret. |
| `auth-bridge:scaffold` | Generates the OAuth controller, middleware, and route stubs. Pass `--force` to overwrite. |
| `auth-bridge:check` | Hits `/health` and (optionally) `/user` using `--token` or `AUTH_BRIDGE_CHECK_TOKEN`. |

### Manual fallback / customization

Prefer the commands, but if you cannot hit the internal bootstrap endpoint from your environment, complete these steps manually:

1. Create an OAuth client in the Auth API (Passport) with your callbacks.
2. Link it to an `applications` row (`key = APP_KEY_SLUG`) and enable the relevant accounts.
3. Install the bridge (`composer require`, publish config/migrations, migrate) and configure the `auth-bridge` guard.
4. Set the `.env` keys (`AUTH_BRIDGE_*`, `OAUTH_CLIENT_*`, `APP_KEY_SLUG`, `APP_URL`).
5. Implement/adjust the Authorization Code controller, callback, and logout actions (the scaffolded files are a good starting point). Store the issued token where `AUTH_BRIDGE_INPUT_KEY`/`AUTH_BRIDGE_STORAGE_KEY` expect it (`api_token` by default).
6. Ensure the `inject-auth-ctx` middleware (or equivalent) attaches the current account/app context headers before `auth:api` (or `auth:auth-bridge`) runs.
7. (Optional) add refresh token handling and create Account API keys for server-to-server use cases.

Guardrails/best practices:

- Protect the Auth API `/internal/apps/bootstrap` endpoint behind admin roles + network allow lists.
- Avoid echoing client secrets in CI logs; the command only writes them to `.env` unless you print Artisan output.
- Run with `--dry` in pull requests to show intent without mutating files.
- Because scaffolding is additive and idempotent, it is safe to re-run after editing routes/controller, but keep customizations separate or use `--force` selectively.

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
