<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\OnboardingState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        if (OnboardingState::isComplete()) {
            return redirect()->route('home');
        }

        return Inertia::render('AuthBridge/Onboarding/Wizard');
    }

    public function getBootstrapToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'totp' => ['nullable', 'string'],
        ]);

        $response = Http::asForm()->post(rtrim($data['base_url'], '/') . '/login', [
            'email' => $data['email'],
            'password' => $data['password'],
            'totp' => $data['totp'] ?? null,
        ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Unable to authenticate with Auth API.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $token = $response->json('access_token');

        if (! $token) {
            return response()->json([
                'message' => 'Auth API did not return an access token.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'token' => $token,
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'auth_base' => ['required', 'url'],
            'token' => ['required', 'string'],
            'app_name' => ['required', 'string'],
            'app_key' => ['required', 'alpha_dash'],
            'redirect' => ['required', 'url'],
            'accounts' => ['nullable', 'string'],
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
        ]);

        $arguments = array_filter([
            '--app-name' => $payload['app_name'] ?? null,
            '--app-key' => $payload['app_key'] ?? null,
            '--redirect' => $payload['redirect'] ?? null,
            '--auth-base' => $payload['auth_base'] ?? null,
            '--bootstrap-token' => $payload['token'] ?? null,
            '--accounts' => $this->parseAccounts($payload['accounts'] ?? ''),
            '--client-id' => $payload['client_id'] ?? null,
            '--client-secret' => $payload['client_secret'] ?? null,
        ]);

        Log::info('Starting Auth Bridge onboarding run.', $arguments);

        $exitCode = Artisan::call('auth-bridge:onboard', $arguments);

        if ($exitCode !== 0) {
            Log::warning('Auth Bridge onboarding failed.', [
                'exit_code' => $exitCode,
                'output' => Artisan::output(),
            ]);

            return response()->json([
                'message' => 'Onboarding failed.',
                'output' => Artisan::output(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        OnboardingState::markComplete([
            'app_name' => $payload['app_name'],
            'auth_base' => $payload['auth_base'],
        ]);

        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('cache:clear');

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int, string>
     */
    private function parseAccounts(string $value): array
    {
        return collect(explode(',', $value))
            ->map(static fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
