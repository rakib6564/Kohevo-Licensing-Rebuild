<?php
/**
 * 0026_remote_license_cache_signed_payload — Phase 10: retain the exact
 * signed check-in envelope alongside the unpacked cache columns
 * (docs/02-architecture/10-CLIENT-LICENSING-DATABASE-DESIGN.md §3,
 * 12-SECURITY-ARCHITECTURE.md §2.7).
 *
 *   raw_payload    the JSON string the Central Server signed, verbatim
 *   raw_signature  the base64 Ed25519 signature that accompanied it
 *   verified_at    when that signature was last verified on write
 *
 * SlateLicenseCacheStore::readTrustState() re-verifies raw_signature over
 * raw_payload on every read and derives the trusted state from the signed
 * bytes only (10 §4), so a direct edit of status/expires_at/entitlements
 * in this table is no longer trusted.
 *
 * Additive and nullable: 10 §3 describes these as NOT NULL for rows the
 * new client writes, but a row written before this migration has no
 * signed payload to put here. Such a row stays readable as a row but is
 * untrusted (exactly like a pre-Phase-4 row with no installation_id) until
 * the next successful check-in replaces it. No existing column is changed.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->table('remote_license_cache', function (Table $t) {
            $t->text('raw_payload')->nullable();
            $t->string('raw_signature', 255)->nullable();
            $t->datetime('verified_at')->nullable();
        });
    }

    public function down(Schema $s): void
    {
        $s->table('remote_license_cache', function (Table $t) {
            $t->drop('raw_payload');
            $t->drop('raw_signature');
            $t->drop('verified_at');
        });
    }
};
