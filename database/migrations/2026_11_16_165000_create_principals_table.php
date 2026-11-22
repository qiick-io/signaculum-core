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
    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            // Keep the principal for audit; erasure sets deactivated_at (core-hardening).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Nullable only for instance_service (CHECK / SQLite recreate below); cascade with the org.
            $table->foreignUuid('organisation_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
            $table->index('user_id');
        });

        $this->enforceOrganisationRequiredUnlessInstanceService();
    }

    public function down(): void
    {
        Schema::dropIfExists('principals');
    }

    /**
     * organisation_id may be NULL only for the instance Admin API service principal.
     * SQLite cannot ADD CONSTRAINT CHECK after create, so the table is rebuilt with CHECK.
     */
    private function enforceOrganisationRequiredUnlessInstanceService(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::drop('principals');

            DB::statement(<<<'SQL'
                CREATE TABLE principals (
                    id varchar(36) not null,
                    type varchar(32) not null,
                    user_id integer null,
                    organisation_id varchar(36) null,
                    name varchar(255) not null,
                    deactivated_at datetime null,
                    created_at datetime null,
                    updated_at datetime null,
                    primary key (id),
                    foreign key (user_id) references users (id) on delete set null,
                    foreign key (organisation_id) references organizations (id) on delete cascade,
                    unique (organisation_id, user_id),
                    check (type = 'instance_service' or organisation_id is not null)
                )
                SQL);

            DB::statement('create index principals_user_id_index on principals (user_id)');

            return;
        }

        DB::statement(
            "ALTER TABLE principals ADD CONSTRAINT principals_organisation_required_unless_instance_service CHECK (type = 'instance_service' OR organisation_id IS NOT NULL)"
        );
    }
};
