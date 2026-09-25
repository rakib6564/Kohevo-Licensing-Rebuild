<?php
/**
 * Slate — MigrationRunner.
 *
 * Discovers migration files, applies the pending ones in order, and records each
 * in a `migrations` ledger so they are never re-run (migrations doc §2). Idempotent
 * by the ledger; ordered by the numeric filename prefix.
 *
 * Coexistence (Phase 1): this governs CORE schema now. Plugin ensureSchema() is
 * left exactly as-is and adopts migrations only when rebuilt. On an EXISTING
 * install whose tables already exist, baseline() stamps the initial migration as
 * applied WITHOUT running it — so the ledger becomes the source of truth with no
 * risk of re-creating live tables.
 *
 * Multi-tenant note: under the default shared-DB driver migrations run once with a
 * single ledger. Per-tenant fan-out (schema-/DB-per-tenant, ADR-0012) is a driver
 * concern layered on later; the migration code is identical.
 *
 * DDL transactionality: MySQL auto-commits DDL, so a failed statement mid-migration
 * cannot be rolled back automatically — the runner applies statements in order and
 * records the migration only after up() completes, then surfaces any error.
 *
 * Layer: Data (uses a PDO connection; depends on Schema + Migration).
 */

declare(strict_types=1);

namespace Slate\Data;

use Slate\Data\Schema\Schema;

final class MigrationRunner
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
        private readonly string $ledgerTable = 'migrations',
    ) {}

    /** Create the ledger table if absent. Safe to call repeatedly. */
    public function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . $this->ledgerTable . '` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration`  VARCHAR(255) NOT NULL,
                `batch`      INT UNSIGNED NOT NULL,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Every discovered migration name (filename without .php), in apply order.
     * Files must match /^\d+_.*\.php$/ so the numeric prefix defines ordering.
     *
     * @return string[]
     */
    public function discover(): array
    {
        $names = [];
        foreach ((array) glob($this->migrationsDir . '/*.php') as $path) {
            $base = basename((string) $path, '.php');
            if (preg_match('/^\d+_/', $base)) {
                $names[] = $base;
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** Migration names already recorded in the ledger. @return string[] */
    public function applied(): array
    {
        $this->ensureLedger();
        $stmt = $this->pdo->query('SELECT `migration` FROM `' . $this->ledgerTable . '`');
        return $stmt ? array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
    }

    /** Discovered migrations not yet applied, in order. @return string[] */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter($this->discover(), fn (string $n) => !in_array($n, $applied, true)));
    }

    /** name => bool applied. @return array<string,bool> */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->discover() as $name) {
            $out[$name] = in_array($name, $applied, true);
        }
        return $out;
    }

    /**
     * Apply pending migrations in one new batch. Returns the names applied.
     *
     * @param string[]|null $only Restrict to these migration names (still skipping
     *        any already applied), in discovery order — for a caller (e.g. the
     *        installer) that must apply specific core migrations without picking
     *        up every other pending one. Default null: all pending, as before.
     * @return string[]
     */
    public function migrate(?array $only = null): array
    {
        $this->ensureLedger();
        $pending = $only !== null
            ? array_values(array_intersect($this->pending(), $only))
            : $this->pending();
        if ($pending === []) {
            return [];
        }
        $batch  = $this->nextBatch();
        $schema = new Schema(fn (string $sql) => $this->pdo->exec($sql), $this->tableExistsProber());
        $done   = [];
        foreach ($pending as $name) {
            $migration = $this->load($name);
            $migration->up($schema);
            $this->record($name, $batch);
            $done[] = $name;
        }
        return $done;
    }

    /**
     * Stamp the given migrations (default: all pending) as applied WITHOUT running
     * up() — for existing installs whose schema already exists. Returns the names
     * stamped.
     *
     * @param string[]|null $names
     * @return string[]
     */
    public function baseline(?array $names = null): array
    {
        $this->ensureLedger();
        $names ??= $this->pending();
        if ($names === []) {
            return [];
        }
        $batch = $this->nextBatch();
        foreach ($names as $name) {
            $this->record($name, $batch);
        }
        return $names;
    }

    /**
     * Roll back the most recent batch (dev/staging). Runs each migration's down()
     * in reverse order and removes it from the ledger. Returns the names rolled back.
     *
     * @return string[]
     */
    public function rollbackLast(): array
    {
        $this->ensureLedger();
        $batch = (int) ($this->pdo->query('SELECT MAX(`batch`) FROM `' . $this->ledgerTable . '`')->fetchColumn() ?: 0);
        if ($batch === 0) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT `migration` FROM `' . $this->ledgerTable . '` WHERE `batch` = ? ORDER BY `migration` DESC');
        $stmt->execute([$batch]);
        $names  = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $schema = new Schema(fn (string $sql) => $this->pdo->exec($sql), $this->tableExistsProber());
        foreach ($names as $name) {
            $this->load($name)->down($schema);
            $del = $this->pdo->prepare('DELETE FROM `' . $this->ledgerTable . '` WHERE `migration` = ?');
            $del->execute([$name]);
        }
        return $names;
    }

    // ── Internals ─────────────────────────────────────────────

    /** @return callable(string $table): bool */
    private function tableExistsProber(): callable
    {
        return function (string $table): bool {
            // SHOW TABLES LIKE doesn't accept a bound placeholder on all drivers;
            // information_schema does and also avoids LIKE wildcard surprises.
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        };
    }

    private function nextBatch(): int
    {
        $max = (int) ($this->pdo->query('SELECT MAX(`batch`) FROM `' . $this->ledgerTable . '`')->fetchColumn() ?: 0);
        return $max + 1;
    }

    private function record(string $name, int $batch): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `' . $this->ledgerTable . '` (`migration`, `batch`) VALUES (?, ?)'
        );
        $stmt->execute([$name, $batch]);
    }

    private function load(string $name): Migration
    {
        $path = $this->migrationsDir . '/' . $name . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Migration file not found: {$path}");
        }
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration '{$name}' must `return` a Slate\\Data\\Migration instance.");
        }
        return $migration;
    }
}
