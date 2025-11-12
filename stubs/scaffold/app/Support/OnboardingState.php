<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

final class OnboardingState
{
    private const LOCK_FILENAME = 'onboarding.json';

    public static function isComplete(): bool
    {
        if (self::isBypassed()) {
            return true;
        }

        if (! File::exists(self::lockPath())) {
            return false;
        }

        return filled(config('auth-bridge.oauth.client_id')) && filled(config('auth-bridge.oauth.client_secret'));
    }

    public static function markComplete(array $meta = []): void
    {
        File::ensureDirectoryExists(dirname(self::lockPath()));

        $payload = array_merge([
            'completed_at' => now()->toIso8601String(),
            'app_key' => config('auth-bridge.app_key'),
        ], $meta);

        File::put(self::lockPath(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function reset(): void
    {
        if (File::exists(self::lockPath())) {
            File::delete(self::lockPath());
        }
    }

    private static function isBypassed(): bool
    {
        $value = env('ONBOARDING_BYPASS');

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function lockPath(): string
    {
        return storage_path('bootstrap/' . self::LOCK_FILENAME);
    }
}
