<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name', 'Laravel App'),
            ],
            'auth' => [
                'isAuthenticated' => fn (): bool => $request->user() !== null,
                'user' => fn (): ?array => $request->user()
                    ? [
                        'id' => $request->user()->getAuthIdentifier(),
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                    ]
                    : null,
            ],
        ];
    }
}
