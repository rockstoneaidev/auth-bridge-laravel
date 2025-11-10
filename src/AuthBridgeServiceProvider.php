<?php

declare(strict_types=1);

namespace AuthBridge\Laravel;

use AuthBridge\Laravel\Console\BootstrapAppCommand;
use AuthBridge\Laravel\Console\CheckCommand;
use AuthBridge\Laravel\Console\InstallCommand;
use AuthBridge\Laravel\Console\OnboardCommand;
use AuthBridge\Laravel\Console\ScaffoldCommand;
use AuthBridge\Laravel\Guards\AuthBridgeGuard;
use AuthBridge\Laravel\Http\AuthBridgeClient;
use AuthBridge\Laravel\Http\Middleware\EnsureAuthBridgePermission;
use AuthBridge\Laravel\Http\Middleware\EnsureAuthBridgeRole;
use AuthBridge\Laravel\Support\AuthBridgeContext;
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

        $this->app->singleton(AuthBridgeClient::class, function (Container $app): AuthBridgeClient {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new AuthBridgeClient(
                baseUrl: rtrim((string) $config->get('auth-bridge.base_url'), '/'),
                userEndpoint: '/' . ltrim((string) $config->get('auth-bridge.user_endpoint'), '/'),
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
        if ($this->app->runningInConsole()) {
            $this->commands(self::COMMANDS);
        }

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

        $this->publishes([
            __DIR__ . '/../config/auth-bridge.php' => $this->app->configPath('auth-bridge.php'),
        ], 'auth-bridge-config');

        $timestamp = date('Y_m_d_His');

        $this->publishes([
            __DIR__ . '/../database/migrations/2025_01_01_000000_add_auth_bridge_columns_to_users_table.php'
            => $this->app->databasePath("migrations/{$timestamp}_add_auth_bridge_columns_to_users_table.php"),
        ], 'auth-bridge-migrations');
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
                client: $app->make(AuthBridgeClient::class),
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
}
