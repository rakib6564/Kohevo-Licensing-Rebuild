<?php
/**
 * 0022_remote_license_cache — the local, client-side cache of the last
 * verified status from a remote license server (see plugins/licensing/
 * for the server itself, and RemoteLicenseClient for the check-in client).
 *
 * One row per tenant — a standalone install typically has exactly one, but
 * this follows the same tenant_id convention as every other core table
 * rather than special-casing single-tenant installs.
 *
 * `status` is a plain VARCHAR, not an ENUM: it echoes a value the remote
 * server reported, which this app doesn't control the vocabulary of —
 * an ENUM here would need a migration every time the server introduces a
 * new status, for no real benefit over the app-level validation
 * RemoteLicenseClient already does before ever calling save().
 *
 * This is explicitly a CACHE, not a source of truth kept in sync with the
 * local `licenses` table — see the chat discussion this was built from:
 * conflating "last verified copy of remote state" with "what a local
 * admin set" was identified as a real design mistake to avoid. Silence
 * (no row, or a stale fetched_at) is never treated as "suspended" — only
 * an explicit signed "suspended" status is, enforced elsewhere by the
 * gate that reads this table (a later phase).
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('remote_license_cache', function (Table $t) {
            $t->id();
            $t->int('tenant_id')->unsigned();
            $t->string('status', 32);
            $t->string('plan', 64)->nullable();
            $t->json('entitlements')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('fetched_at');
            $t->boolean('signature_valid')->default(true);
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->unique('tenant_id', 'uniq_remote_license_cache_tenant');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('remote_license_cache');
    }
};
