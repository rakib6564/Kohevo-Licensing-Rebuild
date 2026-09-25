<?php
/**
 * 0017_licenses — Phase 1E C2: the licensing engine's own table.
 *
 * The raw license key is never stored — only its SHA-256 hash
 * (license_key_hash), shown to the platform admin exactly once at issuance
 * time (LicenseService::issue()'s return value), matching the "avoid
 * storing raw secrets unnecessarily" requirement. No separate activity/
 * history table: license history is read back from the existing audit_log
 * (target = "license#<id>"), per the "reuse the one audit mechanism, don't
 * build a parallel log" constraint.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('licenses', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned();
            $t->char('license_key_hash', 64);
            $t->int('plan_id')->unsigned()->nullable();
            $t->enum('status', ['trial', 'active', 'expired', 'suspended', 'revoked', 'cancelled'])
                ->default('trial');
            $t->datetime('issued_at')->useCurrent();
            $t->datetime('starts_at')->useCurrent();
            $t->datetime('expires_at')->nullable();
            $t->int('activation_limit')->unsigned()->default(1);
            $t->int('activation_count')->unsigned()->default(0);
            $t->json('metadata')->nullable();
            $t->datetime('last_validated_at')->nullable();
            $t->string('revoke_reason', 255)->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->unique('license_key_hash', 'uniq_license_key_hash');
            $t->index('tenant_id', 'idx_license_tenant');
            $t->index('status', 'idx_license_status');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('licenses');
    }
};
