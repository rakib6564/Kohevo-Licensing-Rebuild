<?php
/**
 * 0021_white_label_entitlement — Kohevo Brand Persistence System Phase 6.
 *
 * Data-only: no schema change. plan_entitlements.feature_key is already a
 * freeform string(64) column (0015_platform_plans.php); this inserts one
 * more row into the table that already exists, exactly the mechanism
 * db/migrations/0015_platform_plans.php's own seed uses for its 7 plugin
 * feature keys — 'white_label' is simply not one of those (it has no
 * PluginLoader entry; see EntitlementService::canAccessCapability()'s
 * docblock for why it's checked through a separate, non-plugin-gated path).
 * Same table, same PlanService::save()-editable data model, no new concept.
 *
 * Granted to the 'enterprise' plan only — a deliberate, not-hardcoded-to-
 * any-tenant default a platform admin can freely edit afterward via
 * admin/plans.php, matching every other seeded entitlement in this table.
 * Idempotent: skips if the plan is missing (deleted since 0015 seeded it)
 * or the row already exists, so re-running this migration is a no-op.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;

return new class extends \Slate\Data\Migration {
    private const FEATURE_KEY = 'white_label';
    private const PLAN_SLUG   = 'enterprise';

    public function up(Schema $s): void
    {
        $planId = \Database::value("SELECT id FROM platform_plans WHERE slug = ?", [self::PLAN_SLUG]);
        if (empty($planId)) {
            return; // plan not present (never seeded, or since deleted) — nothing to attach the entitlement to
        }

        $exists = \Database::value(
            "SELECT id FROM plan_entitlements WHERE plan_id = ? AND feature_key = ?",
            [$planId, self::FEATURE_KEY]
        );
        if (!empty($exists)) {
            return; // already granted — do not duplicate
        }

        \Database::insert('plan_entitlements', [
            'plan_id'     => $planId,
            'feature_key' => self::FEATURE_KEY,
            'enabled'     => 1,
        ]);
    }

    public function down(Schema $s): void
    {
        $planId = \Database::value("SELECT id FROM platform_plans WHERE slug = ?", [self::PLAN_SLUG]);
        if (empty($planId)) {
            return;
        }
        \Database::query(
            "DELETE FROM plan_entitlements WHERE plan_id = ? AND feature_key = ?",
            [$planId, self::FEATURE_KEY]
        );
    }
};
