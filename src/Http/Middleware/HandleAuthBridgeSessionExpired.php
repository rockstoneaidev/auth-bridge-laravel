<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class HandleAuthBridgeSessionExpired
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (UnauthorizedHttpException $e) {
            // If the request expects JSON (API), let the exception bubble up to be handled by the Handler
            // as a 401 JSON response.
            if ($request->expectsJson()) {
                throw $e;
            }

            // For web requests, we assume the session/token expired.
            // We clear the auth cookie and redirect to login.
            $cookieName = config('auth-bridge.guard.storage_key', 'api_token');

            // Ensure the 'login' route exists, otherwise fallback to '/'
            $redirectTarget = \Illuminate\Support\Facades\Route::has('login') ? 'login' : '/';

            return redirect()
                ->route($redirectTarget)
                ->withCookie(cookie()->forget($cookieName))
                ->with('status', 'Session expired. Please log in again.');
        }
    }
}
