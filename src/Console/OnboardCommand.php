<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OnboardCommand extends Command
{
    protected $signature = 'auth-bridge:onboard
        {--app-key= : applications.key in Auth API}
        {--app-name= : Display name for the app}
        {--redirect= : Redirect URI (defaults to APP_URL + /oauth/callback)}
        {--accounts=* : Account IDs to enable}
        {--auth-base= : Override AUTH_BRIDGE_BASE_URL}
        {--bootstrap-token= : Admin bearer token for the Auth API internal bootstrap}
        {--client-id= : Use an existing OAuth client id}
        {--client-secret= : Use an existing OAuth client secret}
        {--app-id= : The application ID from the Auth API}
        {--dry : Show what would happen without changing anything}
    ';

    protected $description = 'Bootstrap the app in Auth API and scaffold the current Laravel app to use the Auth Bridge';

    public function handle(): int
    {
        Log::info('OnboardCommand started.');
        $appName = (string) ($this->option('app-name') ?: config('app.name', 'My App'));
        $appKey = (string) ($this->option('app-key') ?: Str::slug($appName));
        $authBase = rtrim((string) ($this->option('auth-base') ?: config('auth-bridge.base_url')), '/');
        Log::info('Using Auth API Base URL: ' . $authBase);
        $redirectSuffix = (string) config('auth-bridge.default_redirect_suffix', '/oauth/callback');
        $redirect = (string) ($this->option('redirect') ?: (rtrim((string) config('app.url'), '/') . $redirectSuffix));

        if ($authBase === '') {
            $this->error('Auth base URL is required. Provide --auth-base or set AUTH_BRIDGE_BASE_URL.');
            return self::FAILURE;
        }

        $clientId = $this->option('client-id');
        $clientSecret = $this->option('client-secret');
        $appId = $this->option('app-id');

        $this->line("App Key: <info>{$appKey}</info>");
        $this->line("App Name: <info>{$appName}</info>");
        $this->line("Auth Base: <info>{$authBase}</info>");
        $this->line("Redirect: <info>{$redirect}</info>");

        if ($this->option('dry')) {
            $this->comment('DRY-RUN: skipping mutations.');
            return self::SUCCESS;
        }

        $this->ensureBridgeInstalled();

        if ($token = $this->option('bootstrap-token')) {
            $client = $this->bootstrapApp(
                authBase: $authBase,
                token: $token,
                appKey: $appKey,
                appName: $appName,
                redirect: $redirect,
                accounts: (array) $this->option('accounts'),
            );

            if (! $client) {
                return self::FAILURE;
            }

            $clientId = $client['client_id'] ?? $clientId;
            $clientSecret = $client['client_secret'] ?? $clientSecret;
            $appId = $client['id'] ?? $appId;
        }

        if (! $clientId || ! $clientSecret) {
            $this->warn('No client id/secret available. Re-run with --client-id/--client-secret or --bootstrap-token.');
        }

        Log::info('OnboardCommand: Preparing to update .env with Client ID: ' . ($clientId ?? 'null') . ' and Client Secret: ' . ($clientSecret ? 'provided' : 'null'));
        Log::info('OnboardCommand: Received App ID option: ' . ($this->option('app-id') ?? 'null'));

        $this->updateEnv(array_filter([
            'AUTH_BRIDGE_BASE_URL' => $authBase,
            'AUTH_BRIDGE_USER_ENDPOINT' => config('auth-bridge.user_endpoint', '/user'),
            'AUTH_BRIDGE_APP_ID' => $appId ?? null,
            'AUTH_BRIDGE_APP_KEY' => $appKey,
            'AUTH_BRIDGE_ACCOUNT_HEADER' => config('auth-bridge.headers.account', 'X-Account-ID'),
            'AUTH_BRIDGE_APP_HEADER' => config('auth-bridge.headers.app', 'X-App-Key'),
            'AUTH_BRIDGE_INPUT_KEY' => config('auth-bridge.guard.input_key', 'api_token'),
            'AUTH_BRIDGE_STORAGE_KEY' => config('auth-bridge.guard.storage_key', 'api_token'),
            'OAUTH_CLIENT_ID' => $clientId ?: null,
            'OAUTH_CLIENT_SECRET' => $clientSecret ?: null,
        ], fn ($value) => ! is_null($value)));

        if ($this->call(ScaffoldCommand::class) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->call(CheckCommand::class) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->info('✅ Onboarding completed.');
        Log::info('OnboardCommand completed.');

        return self::SUCCESS;
    }

    private function ensureBridgeInstalled(): void
    {
        $this->callSilent(VendorPublishCommand::class, ['--tag' => 'auth-bridge-config', '--force' => true]);
        $this->callSilent(VendorPublishCommand::class, ['--tag' => 'auth-bridge-migrations', '--force' => true]);
        $this->call(MigrateCommand::class, ['--force' => true]);
    }

    private function bootstrapApp(string $authBase, string $token, string $appKey, string $appName, string $redirect, array $accounts = []): ?array
    {
        $path = (string) config('auth-bridge.internal_bootstrap_path', '/internal/apps/bootstrap');
        $url = rtrim($authBase, '/') . '/' . ltrim($path, '/');
        $this->info("Bootstrapping app in Auth API… {$url}");

        dump('Auth API URL: ' . $url);
        Log::debug('Auth API URL: ' . $url);

        try {
            $response = Http::withToken($token)->post($url, [
                'app_key' => $appKey,
                'app_name' => $appName,
                'redirect_uri' => $redirect,
                'account_ids' => array_values($accounts),
            ]);

            dump('Auth API Bootstrap Response: ' . $response->body());
            Log::debug('Auth API Bootstrap Response: ' . $response->body());
            Log::info('Auth API Bootstrap Response: ' . $response->body());

            if ($response->failed()) {
                $this->error('Auth API bootstrap failed: ' . $response->body());
                return null;
            }
        } catch (\Throwable $e) {
            dump('Auth API bootstrap request failed: ' . $e->getMessage());
            Log::debug('Auth API bootstrap request failed: ' . $e->getMessage());
            $this->error('Auth API bootstrap request failed: ' . $e->getMessage());
            return null;
        }

        return (array) $response->json();
    }

    private function updateEnv(array $keyValue): void
    {
        Log::info('updateEnv: Data to be written to .env file:', $keyValue);
        $path = base_path('.env');
        Log::info('updateEnv: Attempting to write to .env file at: ' . $path);
        $env = file_exists($path) ? file_get_contents($path) : '';

        foreach ($keyValue as $key => $value) {
            $pattern = "/^" . preg_quote($key, '/') . "=.*$/m";
            $line = $key . '=' . $value;
            $env = preg_match($pattern, $env)
                ? (string) preg_replace($pattern, $line, $env)
                : rtrim($env) . PHP_EOL . $line . PHP_EOL;
        }

        try {
            file_put_contents($path, ltrim($env));
            Log::info('updateEnv: Successfully wrote to .env file.');
        } catch (\Throwable $e) {
            Log::error('updateEnv: Failed to write to .env file: ' . $e->getMessage());
        }
    }
}
