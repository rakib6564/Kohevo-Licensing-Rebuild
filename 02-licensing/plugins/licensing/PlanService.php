<?php
/**
 * Licensing plugin — Plan domain service (Phase 2: Central Licensing
 * Platform Foundation).
 *
 * A Plan is a package/template consulted only when *creating* a License
 * (docs/02-architecture/02-CENTRAL-LICENSING-DOMAIN.md §1.3,
 * docs/00-project/DECISIONS.md §1) — never read again afterward. Its
 * template module set (`licensing_plan_modules`) is a pre-fill convenience
 * for a future license-creation screen, not an entitlement source; the
 * actual per-license grant lives on `LicenseService` instead
 * (docs/02-architecture/04-ENTITLEMENT-ARCHITECTURE.md §4).
 */

declare(strict_types=1);

require_once __DIR__ . '/LicenseService.php';

class PlanService {

    /** @param array{product_id:int,slug:string,name:string,description?:?string,is_active?:bool} $data */
    public static function create(array $data): int {
        $productId = (int) $data['product_id'];
        if (Database::value('SELECT id FROM licensing_products WHERE id = ?', [$productId]) === null) {
            throw new \InvalidArgumentException('Unknown product.');
        }
        return Database::insert('licensing_plans', [
            'product_id'        => $productId,
            'slug'              => (string) $data['slug'],
            'name'              => (string) $data['name'],
            'description'       => !empty($data['description']) ? (string) $data['description'] : null,
            'is_active'         => array_key_exists('is_active', $data) ? (empty($data['is_active']) ? 0 : 1) : 1,
            // Legacy column (docs/02-architecture/09-CENTRAL-DATABASE-DESIGN.md
            // §4 calls it "Removed", but it is deliberately kept — see the
            // migration file's header comment). It is `NOT NULL` with no
            // schema default, and no longer meaningful to new code (the
            // template module set now lives in `licensing_plan_modules`), so
            // every new row simply gets an empty JSON array here.
            'entitlements_json' => '[]',
        ]);
    }

    public static function update(int $planId, array $data): void {
        $row = [];
        if (array_key_exists('name', $data)) $row['name'] = (string) $data['name'];
        if (array_key_exists('description', $data)) $row['description'] = $data['description'] !== '' ? $data['description'] : null;
        if (array_key_exists('is_active', $data)) $row['is_active'] = empty($data['is_active']) ? 0 : 1;
        if ($row === []) return;
        Database::update('licensing_plans', $row, 'id = ?', [$planId]);
    }

    public static function find(int $planId): ?array {
        return Database::row('SELECT * FROM licensing_plans WHERE id = ?', [$planId]);
    }

    public static function listForProduct(int $productId): array {
        return Database::rows('SELECT * FROM licensing_plans WHERE product_id = ? ORDER BY name', [$productId]);
    }

    /**
     * Replace a plan's template module set wholesale. Idempotent — safe to
     * call repeatedly with the same set. This is a pre-fill default only:
     * it has no effect on any License already issued from this plan.
     *
     * `licensing_plan_modules` carries no FK back to `licensing_plans`
     * (migrations/0025_commercial_licensing_rebuild.sql), so without this
     * check a tampered request naming a nonexistent plan_id (e.g. an
     * admin/plans.php `_action=save` POST with `id=999999`) would silently
     * insert orphaned module rows — the delete-then-reinsert below would
     * otherwise "succeed" against a plan that was never actually created or
     * updated.
     *
     * @param string[] $moduleKeys
     * @throws \InvalidArgumentException if the plan does not exist
     */
    public static function setModules(int $planId, array $moduleKeys): void {
        if (Database::value('SELECT id FROM licensing_plans WHERE id = ?', [$planId]) === null) {
            throw new \InvalidArgumentException('Unknown plan.');
        }

        $moduleKeys = array_values(array_unique(array_filter(
            array_map('strval', $moduleKeys),
            fn(string $k) => $k !== ''
        )));

        // Same convention LicenseService::grantModules() already enforces
        // for a License's own modules: Core is implicit and must never be
        // an explicit row, on a Plan template any more than on a License.
        foreach ($moduleKeys as $key) {
            if (in_array($key, LicenseService::CORE_MODULE_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "'$key' is a Core module — Core is implicit and must never be an explicit plan_modules row."
                );
            }
        }

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            Database::delete('licensing_plan_modules', 'plan_id = ?', [$planId]);
            foreach ($moduleKeys as $key) {
                Database::insert('licensing_plan_modules', ['plan_id' => $planId, 'module_key' => $key]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return string[] */
    public static function modules(int $planId): array {
        return array_column(
            Database::rows('SELECT module_key FROM licensing_plan_modules WHERE plan_id = ? ORDER BY module_key', [$planId]),
            'module_key'
        );
    }
}
