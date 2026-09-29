<?php
/**
 * Kohevo Studio (studio-builder) — Schema Verification & Additive Upgrade Manager.
 *
 * Single Schema Authority:
 * - Baseline table creation DDL lives exclusively in `plugins/studio-builder/install.sql`.
 * - This class NEVER duplicates `CREATE TABLE` definitions in PHP.
 * - `ensureBaselineTables()` reads and executes `plugins/studio-builder/install.sql`
 *   directly when self-healing or initializing baseline tables.
 * - `runMigrations(string $from)` applies only version-gated, idempotent additive
 *   upgrades (`ensureColumn`, `ensureIndex`) for versions > 1.0.0.
 * - `schemaIsCurrent()` verifies all 7 `studiobuilder_*` tables, all required columns,
 *   and strict `tenant_id` non-null/no-default constraints via `INFORMATION_SCHEMA`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Infrastructure;

final class StudioSchemaManager
{
    public const TABLE_PREFIX = 'studiobuilder_';

    /**
     * Authoritative column manifest for all 7 Studio tables (v1.0.0 baseline).
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_TABLES = [
        'studiobuilder_pages' => [
            'id',
            'tenant_id',
            'uuid',
            'title',
            'slug',
            'page_type',
            'status',
            'route_mode',
            'active_draft_revision_id',
            'published_revision_id',
            'seo_json',
            'settings_json',
            'published_at',
            'scheduled_for',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ],
        'studiobuilder_revisions' => [
            'id',
            'tenant_id',
            'page_id',
            'revision_number',
            'revision_kind',
            'schema_version',
            'document_json',
            'summary',
            'parent_revision_id',
            'created_by',
            'created_at',
        ],
        'studiobuilder_compilations' => [
            'id',
            'tenant_id',
            'page_id',
            'revision_id',
            'compile_mode',
            'compiled_html',
            'compiled_css',
            'dynamic_manifest_json',
            'head_assets_json',
            'content_hash',
            'compiler_version',
            'compiled_at',
        ],
        'studiobuilder_dependencies' => [
            'id',
            'tenant_id',
            'page_id',
            'revision_id',
            'node_id',
            'dependency_type',
            'dependency_key',
            'created_at',
        ],
        'studiobuilder_templates' => [
            'id',
            'tenant_id',
            'uuid',
            'template_key',
            'template_type',
            'category',
            'name',
            'description',
            'thumbnail_media_id',
            'schema_version',
            'document_json',
            'is_system',
            'created_by',
            'created_at',
            'updated_at',
        ],
        'studiobuilder_tokens' => [
            'id',
            'tenant_id',
            'token_group',
            'schema_version',
            'tokens_json',
            'compiled_css_vars',
            'updated_by',
            'updated_at',
        ],
        'studiobuilder_locks' => [
            'id',
            'tenant_id',
            'page_id',
            'user_id',
            'lock_token',
            'acquired_at',
            'heartbeat_at',
            'expires_at',
        ],
    ];

    /**
     * Canonical path to the single baseline DDL authority (`plugins/studio-builder/install.sql`).
     */
    public static function installSqlPath(?\Plugin $plugin = null): string
    {
        if ($plugin !== null) {
            return $plugin->dir('install.sql');
        }
        return SLATE_ROOT . '/plugins/studio-builder/install.sql';
    }

    /**
     * Verify and self-heal the Studio schema on plugin boot.
     *
     * Fast path: when both `applied_version` and `schema_verified` match the
     * plugin's current version, no INFORMATION_SCHEMA queries run on subsequent requests.
     */
    public static function ensureVerified(\Plugin $plugin): bool
    {
        $version      = $plugin->version();
        $applied      = (string) $plugin->setting('applied_version', '0.0.0');
        $verified     = (string) $plugin->setting('schema_verified', '');
        $needsUpgrade = version_compare($applied, $version, '<');

        if (!$needsUpgrade && $verified === $version) {
            return true;
        }

        $from = $needsUpgrade ? $applied : '0.0.0';
        self::ensureBaselineTables(self::installSqlPath($plugin));
        self::runMigrations($from);

        if (self::schemaIsCurrent()) {
            $plugin->setSetting('applied_version', $version);
            $plugin->setSetting('schema_verified', $version);
            return true;
        }

        return false;
    }

    /**
     * Execute `plugins/studio-builder/install.sql` directly to ensure all baseline
     * `studiobuilder_*` tables exist. Never duplicates CREATE TABLE DDL in PHP.
     */
    public static function ensureBaselineTables(?string $sqlPath = null): void
    {
        $path = $sqlPath ?? self::installSqlPath();
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Studio baseline schema file missing or unreadable: {$path}");
        }

        $sql = (string) file_get_contents($path);
        if (trim($sql) === '') {
            return;
        }

        // Strip SQL line comments before splitting statements on semicolons.
        $sql   = (string) preg_replace('/^--[^\n]*$/m', '', $sql);
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $pdo   = \Database::get();

        foreach ($stmts as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    /**
     * Run version-gated, idempotent additive schema upgrades.
     *
     * Baseline v1.0.0 is defined entirely in `plugins/studio-builder/install.sql`.
     * Future versions (> 1.0.0) add `if (version_compare('1.x.0', $from, '>'))`
     * blocks here calling `self::ensureColumn(...)` and `self::ensureIndex(...)`.
     */
    public static function runMigrations(string $from): void
    {
        // v1.0.0 baseline is provisioned by plugins/studio-builder/install.sql.
        // Future additive migrations for > 1.0.0 belong here.
        if (version_compare('1.0.0', $from, '>')) {
            // No additive alterations needed on top of v1.0.0 install.sql.
        }
    }

    /**
     * Verify that all 7 `studiobuilder_*` tables exist, contain every required column,
     * and enforce `tenant_id INT UNSIGNED NOT NULL` without a default value.
     */
    public static function schemaIsCurrent(): bool
    {
        try {
            $tableNames   = array_keys(self::REQUIRED_TABLES);
            $placeholders = implode(', ', array_fill(0, count($tableNames), '?'));

            $rows = \Database::rows(
                "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ({$placeholders})",
                $tableNames
            );

            $presentColumns = [];
            $tenantColumnOk = [];

            foreach ($rows as $row) {
                $t = (string) $row['TABLE_NAME'];
                $c = (string) $row['COLUMN_NAME'];
                $presentColumns[$t][$c] = true;

                if ($c === 'tenant_id') {
                    $isNotNull = strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'NO';
                    $noDefault = ($row['COLUMN_DEFAULT'] ?? null) === null;
                    $tenantColumnOk[$t] = $isNotNull && $noDefault;
                }
            }

            foreach (self::REQUIRED_TABLES as $table => $requiredCols) {
                if (empty($presentColumns[$table])) {
                    return false;
                }
                if (empty($tenantColumnOk[$table])) {
                    return false;
                }
                foreach ($requiredCols as $col) {
                    if (empty($presentColumns[$table][$col])) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Idempotently add a column to a `studiobuilder_*` table if it does not yet exist.
     */
    public static function ensureColumn(string $table, string $column, string $definition): void
    {
        self::assertStudioTable($table);
        self::assertIdentifier($column, 'column');

        $exists = (int) \Database::value(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?",
            [$table, $column]
        );

        if ($exists === 0) {
            \Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    /**
     * Idempotently add an index to a `studiobuilder_*` table if it does not yet exist.
     */
    public static function ensureIndex(string $table, string $indexName, string $columnsSql, bool $unique = false): void
    {
        self::assertStudioTable($table);
        self::assertIdentifier($indexName, 'index');

        $exists = (int) \Database::value(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?",
            [$table, $indexName]
        );

        if ($exists === 0) {
            $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
            \Database::query("ALTER TABLE `{$table}` ADD {$kind} `{$indexName}` {$columnsSql}");
        }
    }

    private static function assertStudioTable(string $table): void
    {
        if (!str_starts_with($table, self::TABLE_PREFIX) || !preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("StudioSchemaManager only manages studiobuilder_* tables; got '{$table}'.");
        }
    }

    private static function assertIdentifier(string $identifier, string $label): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid Studio schema {$label} identifier '{$identifier}'.");
        }
    }
}
