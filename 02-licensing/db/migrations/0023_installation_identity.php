<?php
/**
 * 0023_installation_identity — QA Fix Round 1 (Phase 4, Fix 5) schema
 * parity: this table already exists on 01-client (its own 0023 migration)
 * but was never ported here, which is exactly why
 * Slate\Services\Licensing\SlateLicenseCacheStore on this side had no way
 * to implement the same installation_id trust verification as the
 * 01-client copy — there was nothing for it to read a local identity from.
 *
 * This product (the central licensing server) does not provision or use
 * its own Installation ID in production — it is not itself a licensed
 * client of anything — so this table is expected to stay empty here. Its
 * purpose is solely to let SlateLicenseCacheStore::readTrustState() behave
 * identically to the 01-client implementation (same query, same fail-
 * closed result when no local identity row exists), so the two copies of
 * that class never diverge in security behavior. See db/migrations/
 * 0023_installation_identity.php on 01-client for the original.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $s->create('installation_identity', function (Table $t) {
            $t->tinyInt('singleton_id')->unsigned()->default(1);
            $t->int('tenant_id')->unsigned();
            $t->char('installation_id', 32);
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at')->useCurrent();
            $t->primary('singleton_id');
            $t->unique('tenant_id', 'uniq_installation_identity_tenant');
            $t->unique('installation_id', 'uniq_installation_identity_value');
        });
    }

    public function down(Schema $s): void
    {
        $s->dropIfExists('installation_identity');
    }
};
