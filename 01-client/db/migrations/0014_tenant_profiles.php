<?php
/**
 * 0014_tenant_profiles — Phase 1E B1: multi-tenant management metadata.
 *
 * `tenants` (db/schema.sql:15-23) is a live, populated production table:
 * id, name, slug, status ENUM('active','suspended','deleted'), created_at.
 * Table::compileAlter() emits a plain ADD COLUMN with no IF NOT EXISTS guard
 * (unlike compileCreate(), which always wraps in CREATE TABLE IF NOT EXISTS)
 * — altering it is avoidable risk for no real benefit. This is a purely
 * additive, 1:1 companion table instead: every new piece of SaaS-platform
 * metadata (owner contact, a richer lifecycle than the legacy status enum,
 * trial window, locale/timezone, default plugin set) lives here, keyed by
 * tenant_id, and the live `tenants` table is never touched.
 *
 * lifecycle_status is intentionally a separate concept from tenants.status —
 * the legacy enum ('active'/'suspended'/'deleted') keeps its existing
 * narrower meaning for any code that already reads it; this table's richer
 * lifecycle (trial/active/suspended/deactivated/archived) is what the new
 * Tenant Management UI (admin/tenants.php) actually drives.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('tenant_profiles', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned();
            $t->string('owner_name', 120)->nullable();
            $t->string('owner_email', 190)->nullable();
            $t->enum('lifecycle_status', ['trial', 'active', 'suspended', 'deactivated', 'archived'])
                ->default('trial');
            $t->datetime('trial_ends_at')->nullable();
            $t->string('timezone', 64)->default('UTC');
            $t->string('locale', 16)->default('en');
            $t->json('default_plugins')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->unique('tenant_id', 'uniq_tenant_profile');
            $t->index('lifecycle_status', 'idx_lifecycle_status');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('tenant_profiles');
    }
};
