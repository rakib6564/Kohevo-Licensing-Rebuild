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

require_once __DIR__ . '/ModuleCatalog.php';
require_once __DIR__ . '/LicenseService.php';

class PlanService {

    /**
     * Tracks plan IDs whose module sets were explicitly configured via
     * setModules() or create(['modules' => ...]), so even an explicit empty
     * module set (`none` / Core-only plan) restricts licenses assigned to it.
     *
     * @var array<int,bool>
     */
    private static array $configuredPlanIds = [];

    /** @param array{product_id:int,slug:string,name:string,description?:?string,is_active?:bool,modules?:array<mixed>} $data */
    public static function create(array $data): int {
        $productId = (int) $data['product_id'];
        if (Database::value('SELECT id FROM licensing_products WHERE id = ?', [$productId]) === null) {
            throw new \InvalidArgumentException('Unknown product.');
        }

        $validatedModules = null;
        if (array_key_exists('modules', $data)) {
            if (!is_array($data['modules'])) {
                throw new \InvalidArgumentException('Plan modules must be an array.');
            }
            $validatedModules = ModuleCatalog::validateCommercialSelection($data['modules']);
        }

        $planId = Database::insert('licensing_plans', [
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

        if ($validatedModules !== null) {
            self::setModules($planId, $validatedModules);
        } else {
            unset(self::$configuredPlanIds[$planId]);
        }

        return $planId;
    }

    public static function update(int $planId, array $data): void {
        $row = [];
        if (array_key_exists('name', $data)) $row['name'] = (string) $data['name'];
        if (array_key_exists('description', $data)) $row['description'] = $data['description'] !== '' ? $data['description'] : null;
        if (array_key_exists('is_active', $data)) $row['is_active'] = empty($data['is_active']) ? 0 : 1;
        if ($row !== []) {
            Database::update('licensing_plans', $row, 'id = ?', [$planId]);
        }
        if (array_key_exists('modules', $data)) {
            if (!is_array($data['modules'])) {
                throw new \InvalidArgumentException('Plan modules must be an array.');
            }
            self::setModules($planId, $data['modules']);
        }
    }

    public static function find(int $planId): ?array {
        return Database::row('SELECT * FROM licensing_plans WHERE id = ?', [$planId]);
    }

    public static function findByProductAndSlug(int $productId, string $slug): ?array {
        return Database::row('SELECT * FROM licensing_plans WHERE product_id = ? AND slug = ?', [$productId, $slug]);
    }

    public static function listForProduct(int $productId): array {
        return Database::rows('SELECT * FROM licensing_plans WHERE product_id = ? ORDER BY name', [$productId]);
    }

    /**
     * Replace a plan's commercial module set wholesale. Idempotent — safe to
     * call repeatedly with the same set. Validates strictly against
     * ModuleCatalog::validateCommercialSelection(), rejecting unknown keys,
     * Core keys, Future/Non-V1 keys (editor, content), Supporting
     * Infrastructure (stripe, stripe-payment), System plugins, and duplicate
     * module keys.
     *
     * @param string[] $moduleKeys
     * @throws \InvalidArgumentException if the plan does not exist or any module key is invalid
     */
    public static function setModules(int $planId, array $moduleKeys): void {
        if (Database::value('SELECT id FROM licensing_plans WHERE id = ?', [$planId]) === null) {
            throw new \InvalidArgumentException('Unknown plan.');
        }

        $validatedKeys = ModuleCatalog::validateCommercialSelection($moduleKeys);

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            Database::delete('licensing_plan_modules', 'plan_id = ?', [$planId]);
            foreach ($validatedKeys as $key) {
                Database::insert('licensing_plan_modules', ['plan_id' => $planId, 'module_key' => $key]);
            }
            $pdo->commit();
            self::$configuredPlanIds[$planId] = true;
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

    /**
     * Whether a plan has an explicit commercial module set configured.
     */
    public static function hasModuleRestrictions(int $planId): bool {
        return !empty(self::$configuredPlanIds[$planId]) || count(self::modules($planId)) > 0;
    }

    /**
     * Validate that a license's requested commercial modules do not exceed the
     * modules allowed by its associated plan.
     *
     * @param list<string> $licenseModules
     * @throws \InvalidArgumentException
     */
    public static function validateLicenseModulesForPlan(int $planId, array $licenseModules, bool $strictPlanSubset = false): void {
        if (!$strictPlanSubset && !self::hasModuleRestrictions($planId)) {
            return;
        }
        $allowed = self::modules($planId);
        foreach ($licenseModules as $moduleKey) {
            if (!in_array($moduleKey, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "Module \"{$moduleKey}\" is not allowed by the selected plan."
                );
            }
        }
    }
}
