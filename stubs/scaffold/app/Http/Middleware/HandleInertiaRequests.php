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
        $user = $request->user('auth-bridge');
        $columns = config('auth-bridge.user', []);

        $externalIdColumn = $columns['external_id_column'] ?? 'external_user_id';
        $accountIdColumn = $columns['account_id_column'] ?? 'external_account_id';
        $accountsColumn = $columns['account_ids_column'] ?? 'external_accounts';
        $appsColumn = $columns['app_ids_column'] ?? 'external_apps';

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name', 'Laravel App'),
                'key' => config('auth-bridge.app_key'),
                'client_id' => config('auth-bridge.oauth.client_id'),
            ],
            'auth' => [
                'isAuthenticated' => $user !== null,
                'portal_url' => $this->portalUrl(),
                'user' => fn (): ?array => $user
                    ? [
                        'id' => $user->getAuthIdentifier(),
                        'name' => $user->name,
                        'email' => $user->email,
                        'external_user_id' => $externalIdColumn ? $user->getAttribute($externalIdColumn) : null,
                        'external_account_id' => $accountIdColumn ? $user->getAttribute($accountIdColumn) : null,
                        'external_accounts' => $accountsColumn ? $user->getAttribute($accountsColumn) : null,
                        'external_apps' => $appsColumn ? $user->getAttribute($appsColumn) : null,
                    ]
                    : null,
            ],
        ];
    }

    private function portalUrl(): ?string
    {
        $url = config('auth-bridge.public_url');

        return $url ? rtrim((string) $url, '/') : null;
    }
}
