<?php
/**
 * Slate — SettingsRepository (Phase 1 C2 proof-of-concept).
 *
 * Reimplements the core "read a tenant-scoped setting" through the new data
 * stack — Slate\Data\Repository (auto tenant scoping) + QueryBuilder +
 * TenantContext — instead of the hand-scoped Database::setting() query. Proven at
 * PARITY with the legacy path (tests/integration/SettingsRepositoryParityTest.php).
 *
 * This is a POC, not a migration: Database::setting() and every existing caller
 * keep working unchanged. In Phase 3 the Settings service is extracted and this
 * becomes its backing repository, with Database::setting() a thin BC forwarder.
 *
 * Layer: Services — MAY depend on Data + Tenancy (via the base Repository).
 */

declare(strict_types=1);

namespace Slate\Services\Settings;

use Slate\Data\Repository;

final class SettingsRepository extends Repository
{
    protected string $table = 'settings';

    /**
     * The current tenant's value for $key, or null if unset — parity with
     * Database::setting($key). Tenant scoping is inherited from the base query()
     * (the `AND tenant_id = ?` predicate is never written here).
     */
    public function value(string $key): ?string
    {
        $value = $this->query()->where('setting_key', $key)->value('setting_value');
        return $value === null ? null : (string) $value;
    }

    /**
     * All of the current tenant's settings as key => value.
     * @return array<string,?string>
     */
    public function allAsMap(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            $out[(string) $row['setting_key']] = $row['setting_value'] === null ? null : (string) $row['setting_value'];
        }
        return $out;
    }
}
