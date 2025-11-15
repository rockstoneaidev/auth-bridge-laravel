<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OnboardingState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNotOnboarded
{
    /**
     * @var string[]
     */
    protected array $except = [
        'onboarding',
        'onboarding/*',
        'health',
        'build/*',
        'assets/*',
        'storage/*',
        'vendor/*',
        'sanctum/csrf-cookie',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (OnboardingState::isComplete()) {
            if ($request->is('onboarding*')) {
                return redirect()->to('/');
            }

            return $next($request);
        }

        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Application setup is not complete.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->to('/onboarding');
    }
}
