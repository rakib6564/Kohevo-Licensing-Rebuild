<?php
/**
 * 0024_remote_license_cache_installation_id — QA Fix Round 1 (Phase 4,
 * Fix 5) schema parity with 01-client's
 * 0025_remote_license_cache_installation_id.php. Nullable and purely
 * additive; see that migration for the full rationale (D14,
 * 10-CLIENT-LICENSING-DATABASE-DESIGN.md §3/§4).
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
