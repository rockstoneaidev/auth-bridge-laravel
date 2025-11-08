<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class OAuthController extends Controller
{
    private function base(): string
    {
        return rtrim((string) config('auth-bridge.base_url'), '/');
    }

    private function clientId(): string
    {
        return (string) env('OAUTH_CLIENT_ID');
    }

    private function clientSecret(): string
    {
        return (string) env('OAUTH_CLIENT_SECRET');
    }

    private function redirectUri(): string
    {
        return route('oauth.callback', absolute: true);
    }

    private function appKey(): string
    {
        return (string) env('APP_KEY_SLUG', 'myapp');
    }

    private function storageKey(): string
    {
        return (string) config('auth-bridge.guard.storage_key', 'api_token');
    }

    public function redirect(Request $request)
    {
        $state = Str::random(32);
        $request->session()->put('oauth_state', $state);

        $url = $this->base() . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        $state = $request->session()->pull('oauth_state');
        abort_unless($state && $state === $request->query('state'), 400, 'Invalid state');

        $response = Http::asForm()->post($this->base() . '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $request->query('code'),
        ]);

        abort_if($response->failed(), 401, 'Token exchange failed');

        $json = $response->json();
        $accessToken = $json['access_token'] ?? null;
        $expiresIn = (int) ($json['expires_in'] ?? 1800);

        $request->session()->put('access_token', $accessToken);
        $request->session()->put('token_expires_at', now()->addSeconds($expiresIn));

        $cookie = Cookie::create($this->storageKey())
            ->withValue((string) $accessToken)
            ->withHttpOnly(false)
            ->withSecure(app()->environment('production'))
            ->withSameSite('lax')
            ->withExpires(time() + $expiresIn);

        session(['x_app_key' => $this->appKey()]);

        return redirect('/')->withCookie($cookie);
    }

    public function logout(Request $request)
    {
        if ($token = $request->session()->pull('access_token')) {
            Http::withToken($token)->post($this->base() . '/logout');
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $cookie = Cookie::create($this->storageKey())
            ->withValue('')
            ->withExpires(time() - 3600);

        return redirect('/login')->withCookie($cookie);
    }
}
