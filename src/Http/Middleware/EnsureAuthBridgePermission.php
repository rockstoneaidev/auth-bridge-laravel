<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Http\Middleware;

use AuthBridge\Laravel\Support\AuthBridgeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureAuthBridgePermission
{
    public function __construct(private readonly AuthBridgeContext $context)
    {
    }

    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $accountId = null, ?string $appKey = null)
    {
        $accountId = $this->resolveIdentifier($accountId, $request, 'account');
        $appKey = $this->resolveIdentifier($appKey, $request, 'app');

        if (! $this->context->hasPermission($permission, $accountId, $appKey)) {
            throw new HttpException(403, 'Missing required permission.');
        }

        return $next($request);
    }

    protected function resolveIdentifier(?string $value, Request $request, string $key): ?string
    {
        if (! $value || in_array($value, ['current', 'context'], true)) {
            return $key === 'account'
                ? $this->context->accountId()
                : $this->context->appKey();
        }

        if (str_starts_with($value, 'route:')) {
            $parameter = substr($value, strlen('route:'));

            return $request->route($parameter);
        }

        return $value;
    }
}
