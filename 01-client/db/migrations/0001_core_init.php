<?php
/**
 * 0001_core_init — the core schema as the first migration.
 *
 * up() runs the complete core schema (db/schema.sql). Because that file is
 * idempotent (CREATE TABLE IF NOT EXISTS throughout), applying this migration on
 * a fresh install creates the core tables, and applying it where they already
 * exist is a no-op.
 *
 * EXISTING installs are `baseline`-stamped (php bin/migrate baseline) — recorded
 * as applied WITHOUT running — so the ledger becomes the source of truth with
 * zero risk of touching live tables. New installs `migrate` (or install.php runs
 * schema.sql directly during Phase 1 coexistence, after which this stays a
 * no-op / can be baselined).
 *
 * Irreversible by design: down() does NOT drop the core tables — that would
 * destroy the entire install. Core is forward-only.
 */

declare(strict_types=1);

use Slate\Data\Schema\Schema;

return new class extends \Slate\Data\Migration {
    public function up(Schema $s): void
    {
        $schemaSql = (string) file_get_contents(SLATE_ROOT . '/db/schema.sql');
        if (trim($schemaSql) === '') {
            throw new \RuntimeException('0001_core_init: db/schema.sql is empty or unreadable.');
        }
        $s->raw($schemaSql);
    }

    public function down(Schema $s): void
    {
        // Intentionally irreversible: core schema is forward-only. Rolling back
        // would drop every core table (tenants, users, settings, …) and destroy
        // the install. Rebuild from a backup instead.
    }
};
