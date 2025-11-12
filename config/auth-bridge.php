<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Credentials
    |--------------------------------------------------------------------------
    |
    | App identification and OAuth credentials for authenticating with the
    | centralized Auth API.
    |
    */

    'app_id' => env('AUTH_BRIDGE_APP_ID'),
    'app_key' => env('APP_KEY_SLUG', 'myapp'),

    /*
    |--------------------------------------------------------------------------
    | OAuth2 Client Configuration
    |--------------------------------------------------------------------------
    |
    | OAuth client credentials for authenticating with the centralized Auth API.
    | These are created via the auth-bridge onboarding process or manually
    | via Laravel Passport in the Auth API.
    |
    */

    'oauth' => [
        'client_id' => env('OAUTH_CLIENT_ID'),
        'client_secret' => env('OAUTH_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth API Base URL (Server-to-Server)
    |--------------------------------------------------------------------------
    |
    | This value is the base URL for the centralized Auth API used for
    | server-to-server communication. Should point to the versioned API root.
    |
    | Examples:
    | - Docker internal: http://auth_api/api/v1
    | - Production internal: https://auth-internal.example.com/api/v1
    | - Production public: https://auth.example.com/api/v1
    |
    */

    'base_url' => env('AUTH_BRIDGE_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Auth API Public URL (Browser Redirects)
    |--------------------------------------------------------------------------
    |
    | This URL is used for OAuth redirects that happen in the user's browser.
    | If not set, falls back to base_url. In many environments, the public URL
    | differs from the internal server-to-server URL.
    |
    | Examples:
    | - Docker: http://localhost:8001/api/v1 (where 8001 is the host port)
    | - Production: https://auth.example.com/api/v1
    |
    */

    'public_url' => env('AUTH_BRIDGE_PUBLIC_URL', env('AUTH_BRIDGE_BASE_URL')),

    /*
    |--------------------------------------------------------------------------
    | User Endpoint
    |--------------------------------------------------------------------------
    |
    | Endpoint that returns the authenticated user context when invoked with
    | a Bearer token. By default, the guard will perform a GET request.
    |
    */

    'user_endpoint' => env('AUTH_BRIDGE_USER_ENDPOINT', '/user'),

    /*
    |--------------------------------------------------------------------------
    | Onboarding Defaults
    |--------------------------------------------------------------------------
    |
    | Values consumed by the onboarding Artisan commands when scaffolding a
    | Laravel application to use the Auth Bridge.
    |
    */

    'internal_bootstrap_path' => env('AUTH_BRIDGE_BOOTSTRAP_PATH', '/internal/apps/bootstrap'),
    'app_lookup_path' => env('AUTH_BRIDGE_APP_LOOKUP_PATH', '/apps'),
    'default_redirect_suffix' => env('AUTH_BRIDGE_DEFAULT_REDIRECT_SUFFIX', '/oauth/callback'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Configure the HTTP client used to contact the Auth API.
    |
    */

    'http' => [
        'timeout' => env('AUTH_BRIDGE_HTTP_TIMEOUT', 5),
        'connect_timeout' => env('AUTH_BRIDGE_HTTP_CONNECT_TIMEOUT', 2),
        'allow_redirects' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Remote user payloads can be cached briefly to avoid repeated network calls
    | within the same request burst. Configure the cache store and TTL (seconds).
    |
    */

    'cache' => [
        'store' => env('AUTH_BRIDGE_CACHE_STORE'),
        'ttl' => env('AUTH_BRIDGE_CACHE_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Header Configuration
    |--------------------------------------------------------------------------
    |
    | These headers are forwarded to the Auth API to provide account/app scope.
    |
    */

    'headers' => [
        'account' => env('AUTH_BRIDGE_ACCOUNT_HEADER', 'X-Account-ID'),
        'app' => env('AUTH_BRIDGE_APP_HEADER', 'X-App-Key'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard Defaults
    |--------------------------------------------------------------------------
    |
    | Default options used by the auth-bridge guard. Individual guard configs
    | can override these values from config/auth.php.
    |
    */

    'guard' => [
        'input_key' => env('AUTH_BRIDGE_INPUT_KEY', 'api_token'),
        'storage_key' => env('AUTH_BRIDGE_STORAGE_KEY', 'api_token'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local User Column Mapping
    |--------------------------------------------------------------------------
    |
    | Configure which columns on your local users table store Auth API context.
    |
    */

    'user' => [
        'model_id_column' => env('AUTH_BRIDGE_MODEL_ID_COLUMN', 'id'),
        'external_id_column' => env('AUTH_BRIDGE_EXTERNAL_ID_COLUMN', 'external_user_id'),
        'account_id_column' => env('AUTH_BRIDGE_ACCOUNT_ID_COLUMN', 'external_account_id'),
        'account_ids_column' => env('AUTH_BRIDGE_ACCOUNT_IDS_COLUMN', 'external_accounts'),
        'app_ids_column' => env('AUTH_BRIDGE_APP_IDS_COLUMN', 'external_apps'),
        'status_column' => env('AUTH_BRIDGE_STATUS_COLUMN', 'external_status'),
        'payload_column' => env('AUTH_BRIDGE_PAYLOAD_COLUMN', 'external_payload'),
        'synced_at_column' => env('AUTH_BRIDGE_SYNCED_AT_COLUMN', 'external_synced_at'),
        'avatar_column' => env('AUTH_BRIDGE_AVATAR_COLUMN', 'avatar_url'),
        'last_seen_column' => env('AUTH_BRIDGE_LAST_SEEN_COLUMN', 'last_seen_at'),
    ],
];
