<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet principals own API keys. Human principals are one per organisation
 * membership (`organisation_id` NOT NULL); the sole exception is a singleton
 * `instance_service` principal for Admin REST API keys (decision 2026-11-22 / #229).
 *
 * @see knowledge/decisions/2026-11-22-principal-organisation-required-in-multi-tenant-products
 * @see knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release
 */
return new class extends Migration
{
    private const string OrganisationCheck = "(type = 'instance_service' AND organisation_id IS NULL) OR (type <> 'instance_service' AND organisation_id IS NOT NULL)";

    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            // Keep the principal for audit; erasure sets deactivated_at (core-hardening).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Null only for instance_service (CHECK / SQLite recreate below). Restrict, never
            // cascade: deleting an organisation is an offboarding job that deactivates its
            // principals and keeps them as evidence (laravel-access design §15.5, 2026-11-23).
            $table->foreignUuid('organisation_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('name');
            $table->timestamp('deactivated_at')->nullable();
            // PrincipalDeactivationReasonEnum; null while active (laravel-access design §15.2).
            $table->string('deactivation_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
            $table->index('user_id');
        });

        $this->enforceOrganisationCheckAndInstanceServiceSingleton();
    }

    public function down(): void
    {
        Schema::dropIfExists('principals');
    }

    /**
     * Bidirectional organisation_id rule + at most one instance_service row.
     * SQLite cannot ADD CONSTRAINT / partial unique after create, so it rebuilds the table.
     */
    private function enforceOrganisationCheckAndInstanceServiceSingleton(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::drop('principals');

            $check = self::OrganisationCheck;

            DB::statement(<<<SQL
                CREATE TABLE principals (
                    id varchar(36) not null,
                    type varchar(32) not null,
                    user_id integer null,
                    organisation_id varchar(36) null,
                    name varchar(255) not null,
                    deactivated_at datetime null,
                    deactivation_reason varchar(32) null,
                    created_at datetime null,
                    updated_at datetime null,
                    primary key (id),
                    foreign key (user_id) references users (id) on delete set null,
                    foreign key (organisation_id) references organizations (id) on delete restrict,
                    unique (organisation_id, user_id),
                    check ({$check})
                )
                SQL);

            DB::statement('create index principals_user_id_index on principals (user_id)');
            DB::statement(
                "create unique index principals_instance_service_unique on principals (type) where type = 'instance_service'"
            );

            return;
        }

        DB::statement(
            'ALTER TABLE principals ADD CONSTRAINT principals_organisation_required_unless_instance_service CHECK ('.self::OrganisationCheck.')'
        );

        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX principals_instance_service_unique ON principals (type) WHERE type = 'instance_service'"
            );

            return;
        }

        if ($driver === 'mysql') {
            // Functional unique: only one row may evaluate to 1; other types are NULL (multi-NULL OK).
            DB::statement(
                'CREATE UNIQUE INDEX principals_instance_service_unique ON principals ((CASE WHEN type = \'instance_service\' THEN 1 END))'
            );

            return;
        }

        // MariaDB 10.11: no functional indexes; generated column + unique (NULL for non-service rows).
        DB::statement(
            'ALTER TABLE principals ADD COLUMN instance_service_singleton TINYINT GENERATED ALWAYS AS (CASE WHEN type = \'instance_service\' THEN 1 ELSE NULL END) STORED'
        );
        DB::statement(
            'CREATE UNIQUE INDEX principals_instance_service_unique ON principals (instance_service_singleton)'
        );
    }
};
