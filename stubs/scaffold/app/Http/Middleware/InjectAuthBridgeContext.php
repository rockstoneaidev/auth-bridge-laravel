<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InjectAuthBridgeContext
{
    public function handle(Request $request, Closure $next)
    {
        if ($account = session('x_account_id')) {
            $request->headers->set(env('AUTH_BRIDGE_ACCOUNT_HEADER', 'X-Account-ID'), $account);
        }

        $request->headers->set(
            env('AUTH_BRIDGE_APP_HEADER', 'X-App-Key'),
            env('APP_KEY_SLUG', 'myapp')
        );

        return $next($request);
    }
}
