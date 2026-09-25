<?php
/**
 * 0002_identity_core — the Phase 2A identity spine (additive).
 *
 * Creates the canonical people model (ADR-0006 / docs/09-Roadmap/phase2a-identity-design.md):
 *   contacts         — the one canonical party (person; organization reserved for 2B)
 *   contact_emails   — normalized emails (dedup/match key)
 *   contact_phones   — E.164 phones (match key; restaurant is phone-primary)
 *   identities       — portal login credential(s); replaces the customers auth surface
 *   identity_tokens  — single-use hashed verify/reset tokens (replaces customer_auth_tokens)
 *
 * PURELY ADDITIVE: no existing table or reader is touched; no data is moved here
 * (seeding is 0003, gated behind a dry-run). `contacts.kind` and `merged_into_id`
 * exist from day one for forward-compat (2B) but are unused in 2A.
 *
 * Reversible: down() drops the five tables in FK-safe order. They are empty at
 * this stage, so rollback is clean.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('contacts', function (Table $t) {
            $t->id();                                              // BIGINT UNSIGNED AUTO_INCREMENT PK
            $t->int('tenant_id')->unsigned()->default(1);
            $t->enum('kind', ['person', 'organization'])->default('person');
            $t->string('display_name', 190)->nullable();
            $t->string('primary_email', 190)->nullable();
            $t->string('primary_phone', 40)->nullable();
            $t->enum('status', ['active', 'archived', 'merged'])->default('active');
            $t->bigInt('merged_into_id')->unsigned()->nullable();  // 2B; unused in 2A
            $t->json('meta')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->index(['tenant_id', 'primary_email'], 'idx_tenant_email');
            $t->index(['tenant_id', 'status'], 'idx_tenant_status');
        });

        $s->create('contact_emails', function (Table $t) {
            $t->id();
            $t->bigInt('contact_id')->unsigned();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('email', 190);
            $t->boolean('is_primary')->default(false);
            $t->boolean('verified')->default(false);
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'email'], 'uniq_tenant_email');
            $t->index('contact_id', 'idx_contact');
        });

        $s->create('contact_phones', function (Table $t) {
            $t->id();
            $t->bigInt('contact_id')->unsigned();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->string('phone', 40);
            $t->boolean('is_primary')->default(false);
            $t->datetime('created_at')->useCurrent();
            $t->index(['tenant_id', 'phone'], 'idx_tenant_phone');
            $t->index('contact_id', 'idx_contact');
        });

        $s->create('identities', function (Table $t) {
            $t->id();
            $t->bigInt('contact_id')->unsigned();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->enum('provider', ['password'])->default('password'); // OAuth/passkey reserved (2B)
            $t->string('credential_ref', 190);
            $t->string('password_hash', 255)->nullable();
            $t->boolean('email_verified')->default(false);           // soft flag, NOT an authz gate
            $t->enum('status', ['active', 'suspended'])->default('active');
            $t->datetime('last_login_at')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->unique(['tenant_id', 'provider', 'credential_ref'], 'uniq_tenant_provider_cred');
            $t->index('contact_id', 'idx_contact');
        });

        $s->create('identity_tokens', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned()->default(1);
            $t->bigInt('identity_id')->unsigned();
            $t->string('purpose', 32);
            $t->char('token_hash', 64);
            $t->datetime('expires_at');
            $t->datetime('used_at')->nullable();
            $t->datetime('created_at')->useCurrent();
            $t->index(['token_hash', 'purpose'], 'idx_token_purpose');
            $t->index(['identity_id', 'purpose'], 'idx_identity_purpose');
            $t->index('expires_at', 'idx_expires');
        });
    }

    public function down(Schema $s): void
    {
        // FK-safe order (children before parents). Tables are empty at 2A.
        $s->dropIfExists('identity_tokens');
        $s->dropIfExists('identities');
        $s->dropIfExists('contact_phones');
        $s->dropIfExists('contact_emails');
        $s->dropIfExists('contacts');
    }
};
