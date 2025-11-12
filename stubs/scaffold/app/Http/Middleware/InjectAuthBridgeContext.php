<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InjectAuthBridgeContext
{
    public function handle(Request $request, Closure $next)
    {
        if ($account = session('x_account_id')) {
            $request->headers->set(config('auth-bridge.headers.account'), $account);
        }

        $request->headers->set(
            config('auth-bridge.headers.app'),
            config('auth-bridge.app_key')
        );

        return $next($request);
    }
}
