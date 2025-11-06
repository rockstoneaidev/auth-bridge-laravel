<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'external_user_id')) {
                $table->uuid('external_user_id')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('external_user_id');
            }

            if (! Schema::hasColumn('users', 'external_account_id')) {
                $table->uuid('external_account_id')->nullable()->after('avatar_url');
            }

            if (! Schema::hasColumn('users', 'external_accounts')) {
                $table->json('external_accounts')->nullable()->after('external_account_id');
            }

            if (! Schema::hasColumn('users', 'external_apps')) {
                $table->json('external_apps')->nullable()->after('external_accounts');
            }

            if (! Schema::hasColumn('users', 'external_status')) {
                $table->string('external_status')->nullable()->after('external_apps');
            }

            if (! Schema::hasColumn('users', 'external_payload')) {
                $table->json('external_payload')->nullable()->after('external_status');
            }

            if (! Schema::hasColumn('users', 'external_synced_at')) {
                $table->timestamp('external_synced_at')->nullable()->after('external_payload');
            }

            if (! Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('external_synced_at');
            }
        });

        $this->relaxNameAndEmail();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = [
                'last_seen_at',
                'external_synced_at',
                'external_payload',
                'external_status',
                'external_apps',
                'external_accounts',
                'external_account_id',
                'external_user_id',
                'avatar_url',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $this->enforceNameAndEmailNotNull();
    }

    protected function relaxNameAndEmail(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (Schema::hasColumn('users', 'name')) {
            $this->makeNullable($driver, 'users', 'name', 'VARCHAR(255)');
        }

        if (Schema::hasColumn('users', 'email')) {
            $this->makeNullable($driver, 'users', 'email', 'VARCHAR(255)');
        }
    }

    protected function enforceNameAndEmailNotNull(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (Schema::hasColumn('users', 'name')) {
            $this->makeNotNull($driver, 'users', 'name', 'VARCHAR(255)');
        }

        if (Schema::hasColumn('users', 'email')) {
            $this->makeNotNull($driver, 'users', 'email', 'VARCHAR(255)');
        }
    }

    protected function makeNullable(string $driver, string $table, string $column, string $type): void
    {
        match ($driver) {
            'mysql' => DB::statement("ALTER TABLE {$table} MODIFY {$column} {$type} NULL"),
            'pgsql' => DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL"),
            'sqlite' => null,
            'sqlsrv' => DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} {$type} NULL"),
            default => null,
        };
    }

    protected function makeNotNull(string $driver, string $table, string $column, string $type): void
    {
        match ($driver) {
            'mysql' => DB::statement("ALTER TABLE {$table} MODIFY {$column} {$type} NOT NULL"),
            'pgsql' => DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL"),
            'sqlite' => null,
            'sqlsrv' => DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} {$type} NOT NULL"),
            default => null,
        };
    }
};
