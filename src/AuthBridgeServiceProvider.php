<?php

declare(strict_types=1);

namespace AuthBridge\Laravel;

use AuthBridge\Laravel\Console\BootstrapAppCommand;
use AuthBridge\Laravel\Console\CheckCommand;
use AuthBridge\Laravel\Console\InstallCommand;
use AuthBridge\Laravel\Console\OnboardCommand;
use AuthBridge\Laravel\Console\ScaffoldCommand;
use AuthBridge\Laravel\Contracts\AuthProviderInterface;
use AuthBridge\Laravel\Guards\AuthBridgeGuard;
use AuthBridge\Laravel\Http\AuthBridgeClient;
use AuthBridge\Laravel\Http\Middleware\EnsureAuthBridgePermission;
use AuthBridge\Laravel\Http\Middleware\EnsureAuthBridgeRole;
use AuthBridge\Laravel\Providers\AuthApiProvider;
use AuthBridge\Laravel\Providers\FirebaseProvider;
use AuthBridge\Laravel\Support\AuthBridgeContext;
use AuthBridge\Laravel\Support\Firebase\JwksCache;
use AuthBridge\Laravel\Support\Firebase\TokenVerifier;
use AuthBridge\Laravel\Support\UserSynchronizer;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Providers\ArtisanServiceProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AuthBridgeServiceProvider extends ServiceProvider
{
    /**
     * @var list<class-string<\Illuminate\Console\Command>>
     */
    private const COMMANDS = [
        OnboardCommand::class,
        BootstrapAppCommand::class,
        ScaffoldCommand::class,
        InstallCommand::class,
        CheckCommand::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/auth-bridge.php', 'auth-bridge');

        // Register the selected authentication provider
        $this->app->singleton(AuthProviderInterface::class, function (Container $app): AuthProviderInterface {
            /** @var ConfigRepository $config */
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
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new AuthBridgeClient(
                baseUrl: rtrim((string) ($config->get('auth-bridge.auth_api.base_url') ?? $config->get('auth-bridge.base_url')), '/'),
                userEndpoint: '/' . ltrim((string) ($config->get('auth-bridge.auth_api.user_endpoint') ?? $config->get('auth-bridge.user_endpoint')), '/'),
                http: $app->make(HttpFactory::class),
                httpConfig: $config->get('auth-bridge.http', []),
            );
        });

        $this->app->singleton(AuthBridgeContext::class, function (Container $app): AuthBridgeContext {
            return new AuthBridgeContext($app['request']);
        });
    }

    public function boot(): void
    {
        $this->commands(self::COMMANDS);

        $this->registerPublishing();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerGuard();
        $this->registerMiddleware();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // existing publishes...
        $this->publishes([
            __DIR__ . '/../config/auth-bridge.php' => $this->app->configPath('auth-bridge.php'),
        ], 'auth-bridge-config');

        $timestamp = '2025_01_01_000000';
        $this->publishes([
            __DIR__ . '/../database/migrations/2025_01_01_000000_add_auth_bridge_columns_to_users_table.php'
                => $this->app->databasePath("migrations/{$timestamp}_add_auth_bridge_columns_to_users_table.php"),
        ], 'auth-bridge-migrations');

        // Publish Svelte pages from stubs/scaffold into a namespaced folder in the app
        $this->publishes([
            __DIR__ . '/../stubs/scaffold/resources/js/Pages' => resource_path('js/Pages/AuthBridge'),
        ], 'auth-bridge-svelte-pages');
    }

    protected function registerGuard(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make('auth');

        $auth->extend('auth-bridge', function (Container $app, string $name, array $config): AuthBridgeGuard {
            $providerName = $config['provider'] ?? null;

            /** @var EloquentUserProvider|null $provider */
            $provider = $providerName
                ? $app->make('auth')->createUserProvider($providerName)
                : null;

            if (! $provider instanceof EloquentUserProvider) {
                throw new InvalidArgumentException('Auth bridge guard requires an Eloquent user provider.');
            }

            /** @var ConfigRepository $configRepository */
            $configRepository = $app->make('config');

            $cacheStore = $config['cache_store'] ?? $configRepository->get('auth-bridge.cache.store');

            /** @var CacheRepository $cache */
            $cache = $app->make('cache')->store($cacheStore);

            $columns = $configRepository->get('auth-bridge.user', []);

            $synchronizer = new UserSynchronizer(
                provider: $provider,
                columns: $columns,
            );

            /** @var Dispatcher $events */
            $events = $app->make(Dispatcher::class);

            return new AuthBridgeGuard(
                name: $name,
                request: $app['request'],
                provider: $app->make(AuthProviderInterface::class),
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
        });
    }

    protected function registerMiddleware(): void
    {
        if (! $this->app->bound('router')) {
            return;
        }

        /** @var Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('auth-bridge.permission', EnsureAuthBridgePermission::class);
        $router->aliasMiddleware('auth-bridge.role', EnsureAuthBridgeRole::class);
    }

    /**
     * Create Auth API provider instance.
     */
    private function createAuthApiProvider(Container $app, ConfigRepository $config): AuthApiProvider
    {
        return new AuthApiProvider($app->make(AuthBridgeClient::class));
    }

    /**
     * Create Firebase provider instance.
     */
    private function createFirebaseProvider(Container $app, ConfigRepository $config): FirebaseProvider
    {
        $projectId = $config->get('auth-bridge.firebase.project_id');

        if (! $projectId) {
            throw new RuntimeException('FIREBASE_PROJECT_ID is required when using firebase provider');
        }

        $cacheStore = $config->get('auth-bridge.cache.store');
        $cache = $app->make('cache')->store($cacheStore);

        $jwksCache = new JwksCache(
            jwksUrl: $config->get('auth-bridge.firebase.jwks_url'),
            cache: $cache,
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
}
