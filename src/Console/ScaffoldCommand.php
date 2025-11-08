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
            'app/Http/Middleware/InjectAuthBridgeContext.php',
            app_path('Http/Middleware/InjectAuthBridgeContext.php')
        );

        $this->registerMiddlewareAlias();
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
        $kernel = app_path('Http/Kernel.php');

        if (! $this->filesystem->exists($kernel)) {
            return;
        }

        $contents = $this->filesystem->get($kernel);

        if (Str::contains($contents, 'inject-auth-ctx')) {
            return;
        }

        if (Str::contains($contents, 'protected $middlewareAliases = [')) {
            $updated = str_replace(
                'protected $middlewareAliases = [',
                "protected \\$middlewareAliases = [\n        'inject-auth-ctx' => \\App\\Http\\Middleware\\InjectAuthBridgeContext::class,",
                $contents,
                $count
            );
        } else {
            $updated = str_replace(
                'protected $routeMiddleware = [',
                "protected \\$routeMiddleware = [\n        'inject-auth-ctx' => \\App\\Http\\Middleware\\InjectAuthBridgeContext::class,",
                $contents,
                $count
            );
        }

        if (($count ?? 0) === 0) {
            $this->warn('Could not automatically register inject-auth-ctx middleware. Add it manually in app/Http/Kernel.php.');
            return;
        }

        $this->filesystem->put($kernel, $updated);
        $this->info('Registered route middleware alias: inject-auth-ctx.');
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
