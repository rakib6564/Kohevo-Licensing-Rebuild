<?php
/**
 * Slate — Database wrapper.
 *
 * Static-class PDO singleton. Every DB call in the shell and every
 * plugin goes through this. Prepared statements only — never
 * interpolate user input into SQL.
 */

declare(strict_types=1);

namespace Slate\Data;

// Phase 1 A3: migrated from includes/Database.php into Slate\Data.
// The global name `Database` is provided by a class_alias in
// src/compat/aliases.php. Behavior is identical to the pre-move class.

class Database {
    private static ?\PDO $pdo = null;

    public static function get(): \PDO {
        if (self::$pdo === null) {
            $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
            $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '')
                  . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $opts = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new \PDO($dsn, DB_USER, DB_PASS, $opts);
            // READ COMMITTED so each statement sees other connections'
            // committed writes immediately. With the default REPEATABLE
            // READ, long-lived PHP-FPM connections can see stale data
            // until commit. Slate isn't a long-running OLTP transaction
            // workload, so READ COMMITTED is the right default.
            self::$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        }
        return self::$pdo;
    }

    /**
     * Execute a query with parameters. Returns the PDOStatement.
     */
    public static function query(string $sql, array $params = []): \PDOStatement {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row or null.
     */
    public static function row(string $sql, array $params = []): ?array {
        $r = self::query($sql, $params)->fetch();
        return $r ?: null;
    }

    /**
     * Fetch all rows.
     */
    public static function rows(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Alias for rows(). Some callers prefer this name.
     */
    public static function all(string $sql, array $params = []): array {
        return self::rows($sql, $params);
    }

    /**
     * Fetch a single scalar value from the first column of the first row.
     */
    public static function value(string $sql, array $params = []) {
        $row = self::query($sql, $params)->fetch(\PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    /**
     * Insert a row, returning the new id.
     */
    public static function insert(string $table, array $data): int {
        $cols         = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($placeholders)", array_values($data));
        return (int) self::get()->lastInsertId();
    }

    /**
     * Update rows. Returns rows-affected count.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set    = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        $params = array_merge(array_values($data), $whereParams);
        $stmt   = self::query("UPDATE `$table` SET $set WHERE $where", $params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows. Returns rows-affected count.
     */
    public static function delete(string $table, string $where, array $params = []): int {
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    /**
     * Read a tenant-scoped setting.
     */
    public static function setting(string $key, ?int $tenantId = null) {
        if ($tenantId === null) $tenantId = current_tenant_id();
        return self::value(
            "SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?",
            [$tenantId, $key]
        );
    }

    /**
     * Write a tenant-scoped setting (upsert).
     */
    public static function setSetting(string $key, $value, ?int $tenantId = null): void {
        if ($tenantId === null) $tenantId = current_tenant_id();
        self::query(
            "INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$tenantId, $key, $value]
        );
    }
}
