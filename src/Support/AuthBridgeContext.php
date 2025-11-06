<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuthBridgeContext
{
    public function __construct(private readonly Request $request)
    {
    }

    /**
     * Retrieve the raw remote user payload.
     *
     * @return array<string, mixed>
     */
    public function user(): array
    {
        return (array) $this->request->attributes->get('auth-bridge.user', []);
    }

    public function accountId(): ?string
    {
        return Arr::get($this->user(), 'context.account.id')
            ?? $this->request->headers->get('X-Account-ID');
    }

    public function appKey(): ?string
    {
        return Arr::get($this->user(), 'app.key')
            ?? $this->request->headers->get('X-App-Key');
    }

    public function hasPermission(string $permission, ?string $accountId = null, ?string $appKey = null): bool
    {
        $accountId ??= $this->accountId();
        $appKey ??= $this->appKey();

        if (! $accountId || ! $appKey) {
            return false;
        }

        $permissions = Arr::get($this->user(), "permissions.{$accountId}.{$appKey}", []);

        return in_array($permission, $permissions, true);
    }

    public function hasRole(string $role, ?string $accountId = null, ?string $appKey = null): bool
    {
        $accountId ??= $this->accountId();
        $appKey ??= $this->appKey();

        if (! $accountId || ! $appKey) {
            return false;
        }

        $roles = Arr::get($this->user(), "roles.{$accountId}.{$appKey}", []);

        return in_array($role, $roles, true);
    }
}
