<?php

use AuthBridge\Laravel\Contracts\AuthProviderInterface;
use AuthBridge\Laravel\Providers\AuthApiProvider;
use AuthBridge\Laravel\Providers\FirebaseProvider;

it('registers firebase provider when configured', function () {
    config(['auth-bridge.provider' => 'firebase']);
    config(['auth-bridge.firebase.project_id' => 'test-project']);
    config(['auth-bridge.firebase.jwks_url' => 'https://example.com/jwks']);
    config(['auth-bridge.firebase.issuer_prefix' => 'https://securetoken.google.com/']);
    
    $provider = app(AuthProviderInterface::class);
    
    expect($provider)->toBeInstanceOf(FirebaseProvider::class);
});

it('registers auth api provider when configured', function () {
    config(['auth-bridge.provider' => 'auth_api']);
    config(['auth-bridge.auth_api.base_url' => 'http://auth.test']);
    
    $provider = app(AuthProviderInterface::class);
    
    expect($provider)->toBeInstanceOf(AuthApiProvider::class);
});

it('throws exception for unknown provider', function () {
    config(['auth-bridge.provider' => 'invalid']);
    
    app(AuthProviderInterface::class);
})->throws(InvalidArgumentException::class, 'Unknown auth provider: invalid');

it('throws exception when firebase project id is missing', function () {
    config(['auth-bridge.provider' => 'firebase']);
    config(['auth-bridge.firebase.project_id' => null]);
    
    app(AuthProviderInterface::class);
})->throws(RuntimeException::class, 'FIREBASE_PROJECT_ID is required');
