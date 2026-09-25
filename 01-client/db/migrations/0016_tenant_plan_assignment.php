<?php
/**
 * 0016_tenant_plan_assignment — Phase 1E C1: link a tenant to its plan.
 *
 * tenant_profiles is Slate's own new table (created this same phase, 0014)
 * with no production data predating this migration — unlike the live
 * `tenants` table, altering it carries none of the "already-populated
 * production table with no IF NOT EXISTS guard on ADD COLUMN" risk
 * documented in 0014's own comment. Still guarded with hasTable(), matching
 * the established precedent for every existing ALTER-mode migration
 * (0009, 0010, 0012).
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;
use Slate\Data\Schema\Table;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        if ($s->hasTable('tenant_profiles')) {
            $s->table('tenant_profiles', function (Table $t) {
                $t->int('plan_id')->unsigned()->nullable()->after('locale');
            });
        }
    }

    public function down(Schema $s): void
    {
        if ($s->hasTable('tenant_profiles')) {
            $s->table('tenant_profiles', function (Table $t) {
                $t->drop('plan_id');
            });
        }
    }
};
