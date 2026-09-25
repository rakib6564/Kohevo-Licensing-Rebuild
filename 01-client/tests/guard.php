<?php
/**
 * Refuse to run the DB-backed suites against anything but a test database.
 *
 * The integration suite writes real rows. Pointed at production it leaves
 * fixture identities, synthetic tenants and login attempts in live tables — this
 * happened, and the rows were only noticed because a unique key later collided.
 * Nothing about that failure was loud at the time.
 *
 * The check is POSITIVE IDENTIFICATION, not a denylist. A database has to say it
 * is a test database, by carrying the marker that tests/bin/provision-test-db.sh
 * writes into it. Matching on names would mean the guard silently lapses the
 * first time someone provisions a database that does not fit the pattern, and
 * the failure mode of a lapsed guard is the exact accident it exists to prevent.
 *
 * A production database has no reason to carry this row, and adding one is
 * deliberate enough that it cannot happen by accident.
 *
 * Bypass, only for a deliberate read-only smoke against production:
 *   SLATE_ALLOW_LIVE_DB=1 php tests/smoke.php
 */

declare(strict_types=1);

/** Marker row written by the provisioning script. */
if (!defined('SLATE_TEST_DB_MARKER')) {
    define('SLATE_TEST_DB_MARKER', 'slate_test_database');
}

if (!function_exists('slate_require_test_database')) {
    function slate_require_test_database(): void
    {
        if (getenv('SLATE_ALLOW_LIVE_DB') === '1') {
            fwrite(STDERR, "# WARNING: SLATE_ALLOW_LIVE_DB=1 — running against a non-test database on purpose.\n");
            return;
        }

        $name = defined('DB_NAME') ? (string) DB_NAME : '(unknown)';

        try {
            $marked = \Database::value(
                'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1',
                [SLATE_TEST_DB_MARKER]
            );
        } catch (\Throwable $e) {
            // A missing settings table means this is not a provisioned Slate test
            // database either. Fail closed.
            $marked = null;
        }

        if ((string) $marked === '1') {
            return;
        }

        fwrite(STDERR, <<<TXT

            ─────────────────────────────────────────────────────────────────
            REFUSING TO RUN: '{$name}' is not marked as a test database.

            The DB-backed suites write rows. Running them against a live
            database leaves fixture data in production tables.

            Provision an isolated database for this checkout:

                bash tests/bin/provision-test-db.sh

            That creates one, points this checkout's .env at it, and writes the
            marker this guard looks for.

            To run against this database anyway (read-only smoke only):

                SLATE_ALLOW_LIVE_DB=1 php tests/smoke.php
            ─────────────────────────────────────────────────────────────────

            TXT);
        exit(1);
    }
}
