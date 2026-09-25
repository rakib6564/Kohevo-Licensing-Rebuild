<?php
/**
 * Regression: a semicolon inside a SQL comment must not decapitate the schema.
 *
 * `MultilangTranslate::ensureSchema()` split install.sql with a plain
 * `explode(';')`. Line 49 of that file read:
 *
 *     -- multilangtranslate_strings; matched strings are silently skipped.
 *
 * so the split landed mid-sentence. The tail — the rest of the prose, then the
 * next `CREATE TABLE` — still contained the words "CREATE TABLE", passed the
 * filter, and was executed as SQL. It logged a 1064 syntax error on every
 * request for four days (1,002 entries, 352 KB of data/slate.log), and because
 * the try wrapped the whole loop, the exception abandoned every statement after
 * it. That cost nothing only because the broken statement happened to be last
 * in the file.
 *
 * Two independent failures, so two assertions:
 *   1. splitting must not be fooled by a semicolon in a comment
 *   2. one failing statement must not abandon the ones after it
 *
 * The second needs a real database — a pure parsing test would pass against the
 * old code the moment the split was fixed, and miss the loop-abort half
 * entirely. Tables are created in the test database and dropped again.
 */

declare(strict_types=1);

// Require the class rather than activating the plugin: activation runs boot
// filters that would change what every other suite in this directory sees, and
// splitSchema() needs none of them. Same reason small-business-kit is required
// rather than activated in CI.
require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/MultilangTranslate.php';

unit('splitSchema() is not fooled by a semicolon inside a comment', function (): void {
    $sql = <<<SQL
-- A comment that mentions foo; and then keeps going.
CREATE TABLE a (id INT);
-- Another; with a semicolon.
CREATE TABLE b (id INT);
SQL;

    $stmts = \MultilangTranslate::splitSchema($sql);
    $creates = array_values(array_filter($stmts, fn ($s) => stripos($s, 'CREATE TABLE') !== false));

    assert_eq(2, count($creates), 'both CREATE TABLEs survive the split');

    // The real defect: a fragment that begins with prose but contains the words
    // "CREATE TABLE" passes the filter and reaches the database as SQL.
    foreach ($creates as $s) {
        assert_true(
            stripos(ltrim($s), 'CREATE TABLE') === 0,
            'every executable fragment starts with CREATE TABLE, got: ' . substr($s, 0, 48)
        );
    }
});

unit('a failing statement does not abandon the CREATE TABLEs after it', function (): void {
    $pdo    = Database::get();
    $suffix = 'mltsplit_' . bin2hex(random_bytes(3));
    $early  = "zz_{$suffix}_early";
    $late   = "zz_{$suffix}_late";

    $cleanup = function () use ($pdo, $early, $late): void {
        foreach ([$early, $late] as $t) {
            try { $pdo->exec("DROP TABLE IF EXISTS `{$t}`"); } catch (\Throwable $e) {}
        }
    };
    $cleanup();

    // Mirrors install.sql's shape: a comment carrying a semicolon, a valid
    // CREATE, a statement that cannot execute, then another valid CREATE.
    $schema = <<<SQL
-- Rows are matched by hash; unmatched strings are silently skipped.
CREATE TABLE IF NOT EXISTS `{$early}` (`id` INT UNSIGNED NOT NULL);
CREATE TABLE IF NOT EXISTS `{$early}` (this is not valid SQL at all);
CREATE TABLE IF NOT EXISTS `{$late}` (`id` INT UNSIGNED NOT NULL);
SQL;

    // The loop under test, exactly as ensureSchema() runs it.
    foreach (\MultilangTranslate::splitSchema($schema) as $stmt) {
        if (stripos($stmt, 'CREATE TABLE') === false) continue;
        try { $pdo->exec($stmt); } catch (\Throwable $e) { /* per-statement, as in the fix */ }
    }

    $exists = function (string $t) use ($pdo): bool {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    };

    $earlyMade = $exists($early);
    $lateMade  = $exists($late);
    $cleanup();

    assert_true($earlyMade, 'the CREATE before the bad statement ran');
    // The assertion that fails without the fix: with the try outside the loop,
    // the bad statement throws and this table is never created.
    assert_true($lateMade, 'the CREATE after the bad statement still ran');
});
