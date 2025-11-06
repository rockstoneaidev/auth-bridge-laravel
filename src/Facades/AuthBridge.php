<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Facades;

use AuthBridge\Laravel\Support\AuthBridgeContext;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array user()
 * @method static string|null accountId()
 * @method static string|null appKey()
 * @method static bool hasPermission(string $permission, ?string $accountId = null, ?string $appKey = null)
 * @method static bool hasRole(string $role, ?string $accountId = null, ?string $appKey = null)
 */
class AuthBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuthBridgeContext::class;
    }
}
