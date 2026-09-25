<?php
/**
 * 0025_remote_license_cache_installation_id — Phase 4: Installation Identity
 * binding for the client-side verified license cache.
 *
 * Per docs/02-architecture/15-PHASE-1-DECISIONS.md (D14) and
 * docs/02-architecture/10-CLIENT-LICENSING-DATABASE-DESIGN.md §3, the
 * signed check-in payload now carries the Central Server's own resolved
 * `installation_id` for this install (11-LICENSING-API-CONTRACT.md §2).
 * `RemoteLicenseClient::checkIn()` verifies it matches this install's own
 * `installation_identity.installation_id` at write time; this column lets
 * `SlateLicenseCacheStore::load()` re-assert that same match at read time
 * (10 §4) -- so a `raw_payload`/`raw_signature` pair copied verbatim from a
 * different, legitimately-licensed installation's cache is rejected the
 * moment it's loaded here too, not only when it was first written.
 *
 * Nullable and purely additive: an existing cache row written before this
 * migration (or by a not-yet-updated client) has no installation_id on
 * record and is left exactly as readable as it always was -- see
 * SlateLicenseCacheStore::load()'s own comment for why that is the correct,
 * intentionally narrow scope of this specific check (it is not a substitute
 * for re-verifying raw_payload/raw_signature at read time, which remains a
 * RECOMMENDED-not-LOCKED, not-yet-built hardening per 10 §3/§4).
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->table('remote_license_cache', function (Table $t) {
            $t->char('installation_id', 32)->nullable();
        });
    }

    public function down(Schema $s): void
    {
        $s->table('remote_license_cache', function (Table $t) {
            $t->drop('installation_id');
        });
    }
};
