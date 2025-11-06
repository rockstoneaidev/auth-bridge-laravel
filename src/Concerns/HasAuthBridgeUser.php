<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Concerns;

trait HasAuthBridgeUser
{
    public function initializeHasAuthBridgeUser(): void
    {
        $casts = [
            'external_accounts' => 'array',
            'external_apps' => 'array',
            'external_payload' => 'array',
            'external_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];

        $this->casts = isset($this->casts)
            ? array_merge($casts, $this->casts)
            : $casts;
    }
}
