<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class UserSynchronizer
{
    /**
     * @param  array<string, string|null>  $columns
     */
    public function __construct(
        private readonly EloquentUserProvider $provider,
        private readonly array $columns = [],
    ) {
    }

    /**
     * Synchronize the local user model with the payload returned by the Auth API.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function sync(array $payload, array $context = []): Authenticatable
    {
        $externalIdColumn = $this->columns['external_id_column'] ?? 'external_user_id';
        $externalId = Arr::get($payload, 'id');

        if (! is_string($externalId) || $externalId === '') {
            throw new RuntimeException('Auth API payload is missing the user identifier.');
        }

        /** @var class-string<Model&Authenticatable> $modelClass */
        $modelClass = $this->provider->getModel();

        /** @var Model&Authenticatable|null $user */
        $user = $modelClass::query()
            ->where($externalIdColumn, $externalId)
            ->first();

        if (! $user) {
            $user = new $modelClass();
        }

        $passwordColumn = method_exists($user, 'getAuthPasswordName')
            ? $user->getAuthPasswordName()
            : 'password';

        if (! $user->getAttribute($passwordColumn)) {
            $user->setAttribute(
                $passwordColumn,
                password_hash(Str::random(40), PASSWORD_BCRYPT),
            );
        }

        $email = Arr::get($payload, 'email');

        $accounts = Arr::get($payload, 'accounts', []);
        $apps = Arr::wrap(Arr::get($payload, 'apps'));

        if (empty($apps) && is_array($accounts)) {
            $apps = collect($accounts)
                ->flatMap(static fn ($account): array => Arr::get($account, 'apps', []))
                ->unique('id')
                ->values()
                ->all();
        }

        $accountId = Arr::get($context, 'account_id')
            ?? Arr::get($payload, 'account.id')
            ?? Arr::get($payload, 'accounts.0.id');

        $attributes = [
            $externalIdColumn => $externalId,
            'name' => Arr::get($payload, 'name'),
            'email' => is_string($email) ? strtolower($email) : $email,
        ];

        $statusColumn = $this->columns['status_column'] ?? 'external_status';
        $accountIdColumn = $this->columns['account_id_column'] ?? 'external_account_id';
        $accountIdsColumn = $this->columns['account_ids_column'] ?? 'external_accounts';
        $appIdsColumn = $this->columns['app_ids_column'] ?? 'external_apps';
        $payloadColumn = $this->columns['payload_column'] ?? 'external_payload';
        $syncedAtColumn = $this->columns['synced_at_column'] ?? 'external_synced_at';
        $avatarColumn = $this->columns['avatar_column'] ?? 'avatar_url';
        $lastSeenColumn = $this->columns['last_seen_column'] ?? 'last_seen_at';

        if ($statusColumn) {
            $attributes[$statusColumn] = Arr::get($payload, 'status');
        }

        if ($accountIdColumn) {
            $attributes[$accountIdColumn] = $accountId;
        }

        if ($accountIdsColumn) {
            $attributes[$accountIdsColumn] = $accounts;
        }

        if ($appIdsColumn) {
            $attributes[$appIdsColumn] = $apps;
        }

        if ($payloadColumn) {
            $attributes[$payloadColumn] = $payload;
        }

        if ($syncedAtColumn) {
            $attributes[$syncedAtColumn] = Carbon::now();
        }

        if ($avatarColumn) {
            $attributes[$avatarColumn] = Arr::get($payload, 'avatar_url');
        }

        if ($lastSeenColumn) {
            $lastSeen = Arr::get($payload, 'last_seen_at');
            $attributes[$lastSeenColumn] = $lastSeen ? Carbon::parse($lastSeen) : null;
        }

        if ($verifiedAt = Arr::get($payload, 'email_verified_at')) {
            $attributes['email_verified_at'] = Carbon::parse($verifiedAt);
        }

        $user->forceFill($attributes);

        $user->save();

        return $user;
    }
}
