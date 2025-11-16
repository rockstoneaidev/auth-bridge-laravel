<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ScaffoldCommand extends Command
{
    protected $signature = 'auth-bridge:scaffold {--force : Overwrite existing scaffolding files}';

    protected $description = 'Add Auth Bridge controllers, middleware, routes, and frontend scaffolding';

    private const PLACEHOLDER_TOKEN = 'AUTH_BRIDGE_PLACEHOLDER';

    private Filesystem $filesystem;

    public function handle(Filesystem $filesystem): int
    {
        $this->filesystem = $filesystem;

        $this->scaffoldBackend();
        $this->scaffoldFrontend();

        $this->info('Scaffolding complete.');

        return self::SUCCESS;
    }

    private function scaffoldBackend(): void
    {
        $this->publishFileFromStub(
            'app/Http/Controllers/Auth/OAuthController.php',
            app_path('Http/Controllers/Auth/OAuthController.php')
        );

        $this->publishFileFromStub(
            'app/Http/Controllers/OnboardingController.php',
            app_path('Http/Controllers/OnboardingController.php')
        );

        $this->publishFileFromStub(
            'app/Http/Middleware/InjectAuthBridgeContext.php',
            app_path('Http/Middleware/InjectAuthBridgeContext.php')
        );

        $this->publishFileFromStub(
            'app/Http/Middleware/RedirectIfNotOnboarded.php',
            app_path('Http/Middleware/RedirectIfNotOnboarded.php')
        );

        $this->publishFileFromStub(
            'app/Support/OnboardingState.php',
            app_path('Support/OnboardingState.php')
        );

        $this->registerMiddlewareAlias();
        $this->registerOnboardingMiddleware();
        $this->ensureAuthGuardConfigured();
        $this->appendRoutes();
    }

    private function scaffoldFrontend(): void
    {
        $this->publishFileFromStub('resources/views/app.blade.php', resource_path('views/app.blade.php'));
        $this->publishFileFromStub('resources/css/app.css', resource_path('css/app.css'));
        $this->publishFileFromStub('resources/js/app.js', resource_path('js/app.js'));

        $this->publishDirectoryFromStub('resources/js/components', resource_path('js/components'));
        $this->publishDirectoryFromStub('resources/js/lib', resource_path('js/lib'));
        $this->publishDirectoryFromStub('resources/js/Layouts', resource_path('js/Layouts'));
        $this->publishDirectoryFromStub('resources/js/Pages', resource_path('js/Pages'));
    }

    private function registerMiddlewareAlias(): void
    {
        $bootstrap = base_path('bootstrap/app.php');

        if (! $this->filesystem->exists($bootstrap)) {
            return;
        }

        $contents = $this->filesystem->get($bootstrap);

        if (Str::contains($contents, "'inject-auth-ctx'")) {
            return;
        }

        $needle = '->withMiddleware(function (Middleware $middleware): void {';

        if (! Str::contains($contents, $needle)) {
            $this->warn('Unable to locate withMiddleware() closure in bootstrap/app.php. Register inject-auth-ctx alias manually.');
            return;
        }

        $snippet = "        \$middleware->alias([\n            'inject-auth-ctx' => \\App\\Http\\Middleware\\InjectAuthBridgeContext::class,\n        ]);\n";

        $updated = Str::replaceFirst($needle, $needle . "\n" . $snippet, $contents);

        $this->filesystem->put($bootstrap, $updated);
        $this->info('Registered inject-auth-ctx middleware alias via bootstrap/app.php.');
    }

    private function registerOnboardingMiddleware(): void
    {
        $bootstrap = base_path('bootstrap/app.php');

        if (! $this->filesystem->exists($bootstrap)) {
            return;
        }

        $contents = $this->filesystem->get($bootstrap);
        $middleware = '\\App\\Http\\Middleware\\RedirectIfNotOnboarded::class';

        if (Str::contains($contents, $middleware)) {
            return;
        }

        $needle = '->withMiddleware(function (Middleware $middleware): void {';

        if (! Str::contains($contents, $needle)) {
            $this->warn('Unable to locate withMiddleware() closure in bootstrap/app.php. Add RedirectIfNotOnboarded manually.');
            return;
        }

        $snippet = "        \$middleware->appendToGroup('web', [\n            {$middleware},\n        ]);\n";

        $updated = Str::replaceFirst($needle, $needle . "\n" . $snippet, $contents);

        $this->filesystem->put($bootstrap, $updated);
        $this->info('Registered RedirectIfNotOnboarded in the web middleware group via bootstrap/app.php.');
    }

    private function ensureAuthGuardConfigured(): void
    {
        $authConfig = config_path('auth.php');

        if (! $this->filesystem->exists($authConfig)) {
            return;
        }

        $contents = $this->filesystem->get($authConfig);

        if (Str::contains($contents, "'auth-bridge' => [")) {
            return;
        }

        $needle = "'guards' => [";

        if (! Str::contains($contents, $needle)) {
            $this->warn('Unable to locate guards array in config/auth.php. Add the auth-bridge guard manually.');
            return;
        }

        $guardSnippet = "        'auth-bridge' => [\n"
            . "            'driver' => 'auth-bridge',\n"
            . "            'provider' => 'users',\n"
            . "            'cache_ttl' => env('AUTH_BRIDGE_CACHE_TTL', 30),\n"
            . "        ],\n";

        $updated = Str::replaceFirst($needle, $needle . "\n" . $guardSnippet, $contents);

        $this->filesystem->put($authConfig, $updated);
        $this->info('Added auth-bridge guard definition to config/auth.php.');
    }

    private function appendRoutes(): void
    {
        $path = base_path('routes/web.php');

        if (! $this->filesystem->exists($path)) {
            $this->filesystem->put($path, "<?php\n\n");
        }

        $contents = $this->filesystem->get($path);

        if (Str::contains($contents, 'AuthBridge scaffolding')) {
            $this->line('Routes already contain Auth Bridge scaffolding.');
            return;
        }

        $snippet = $this->filesystem->get($this->stubPath('routes/web.stub'));
        $this->filesystem->append($path, PHP_EOL . $snippet . PHP_EOL);
        $this->info('Appended routes to routes/web.php.');
    }

    private function publishDirectoryFromStub(string $relativeSource, string $targetDir): void
    {
        $sourceDir = $this->stubPath($relativeSource);

        if (! $this->filesystem->isDirectory($sourceDir)) {
            return;
        }

        $files = $this->filesystem->allFiles($sourceDir);

        foreach ($files as $file) {
            $relativePath = Str::after($file->getPathname(), rtrim($sourceDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
            $destination = $targetDir . DIRECTORY_SEPARATOR . $relativePath;
            $this->publishRawFile($file->getPathname(), $destination);
        }
    }

    private function publishFileFromStub(string $relativeSource, string $destination): void
    {
        $source = $this->stubPath($relativeSource);

        if (! $this->filesystem->exists($source)) {
            return;
        }

        $this->publishRawFile($source, $destination);
    }

    private function publishRawFile(string $source, string $destination): void
    {
        $force = (bool) $this->option('force');
        $relative = Str::after($destination, base_path() . DIRECTORY_SEPARATOR);

        if ($this->filesystem->exists($destination)) {
            if (! $force && ! $this->allowsOverwrite($destination)) {
                $this->line("Skipped {$relative} (already exists). Use --force to overwrite.");
                return;
            }
        } else {
            $this->filesystem->ensureDirectoryExists(dirname($destination));
        }

        $this->filesystem->put($destination, $this->filesystem->get($source));
        $this->info("Published {$relative}");
    }

    private function allowsOverwrite(string $path): bool
    {
        $contents = $this->filesystem->get($path);

        return $this->containsPlaceholder($contents);
    }

    private function containsPlaceholder(string $contents): bool
    {
        return str_contains($contents, self::PLACEHOLDER_TOKEN);
    }

    private function stubPath(string $relative): string
    {
        return __DIR__ . '/../../stubs/scaffold/' . ltrim($relative, '/');
    }
}
