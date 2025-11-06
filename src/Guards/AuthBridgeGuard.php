<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Guards;

use AuthBridge\Laravel\Http\AuthBridgeClient;
use AuthBridge\Laravel\Support\UserSynchronizer;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Validated;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthBridgeGuard implements Guard
{
    use GuardHelpers;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly string $name,
        private Request $request,
        private readonly AuthBridgeClient $client,
        private readonly UserSynchronizer $synchronizer,
        private readonly CacheRepository $cache,
        private readonly Dispatcher $events,
        private readonly array $config = [],
    ) {
    }

    public function user(): ?Authenticatable
    {
        if (! is_null($this->user)) {
            return $this->user;
        }

        $token = $this->getTokenForRequest();

        if (! $token) {
            return null;
        }

        $headers = $this->resolveContextHeaders();

        $cacheKey = $this->cacheKey($token, $headers);
        $ttl = (int) ($this->config['cache_ttl'] ?? 30);

        if ($ttl > 0) {
            $payload = $this->cache->remember(
                $cacheKey,
                $ttl,
                fn () => $this->fetchRemoteUser($token, $headers),
            );
        } else {
            $payload = $this->fetchRemoteUser($token, $headers);
        }

        $context = [
            'account_id' => Arr::get($payload, 'context.account.id')
                ?? $headers[$this->config['headers']['account'] ?? 'X-Account-ID'] ?? null,
            'app_key' => Arr::get($payload, 'app.key')
                ?? $headers[$this->config['headers']['app'] ?? 'X-App-Key'] ?? null,
        ];

        $user = $this->synchronizer->sync($payload, $context);

        $this->setUser($user);

        $this->events->dispatch(new Authenticated($this->name, $user));

        $this->request->setUserResolver(fn () => $this->user);

        $this->request->attributes->set('auth-bridge.user', $payload);

        return $this->user;
    }

    public function validate(array $credentials = []): bool
    {
        $user = $this->user();

        if ($user) {
            $this->events->dispatch(new Validated($this->name, $user));

            return true;
        }

        $this->events->dispatch(new Failed($this->name, null, $credentials));

        return false;
    }

    public function logout(): void
    {
        if ($this->user) {
            $this->events->dispatch(new Logout($this->name, $this->user));
        }

        $this->user = null;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    protected function fetchRemoteUser(string $token, array $headers): array
    {
        try {
            return $this->client->fetchUser($token, $headers);
        } catch (RequestException $exception) {
            throw new UnauthorizedHttpException('Bearer', 'Auth API rejected the supplied token.', $exception);
        }
    }

    /**
     * @return array<string, string|null>
     */
    protected function resolveContextHeaders(): array
    {
        $accountHeader = $this->config['headers']['account'] ?? 'X-Account-ID';
        $appHeader = $this->config['headers']['app'] ?? 'X-App-Key';

        return array_filter([
            $accountHeader => $this->request->headers->get($accountHeader)
                ?? $this->request->input(Str::snake($accountHeader)),
            $appHeader => $this->request->headers->get($appHeader)
                ?? $this->request->input(Str::snake($appHeader)),
        ]);
    }

    protected function cacheKey(string $token, array $headers = []): string
    {
        $headerString = collect($headers)
            ->map(fn ($value, $key) => "{$key}:{$value}")
            ->sort()
            ->implode(';');

        return "auth-bridge:{$this->name}:" . sha1($token . '|' . $headerString);
    }

    protected function getTokenForRequest(): ?string
    {
        $token = $this->request->bearerToken();

        if (empty($token) && ($inputKey = $this->config['input_key'] ?? null)) {
            $token = $this->request->input($inputKey);
        }

        if (empty($token) && ($storageKey = $this->config['storage_key'] ?? null)) {
            $token = $this->request->cookie($storageKey);
        }

        return $token ?: null;
    }
}
