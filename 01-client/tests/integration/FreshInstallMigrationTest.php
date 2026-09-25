<?php
/**
 * Fresh-install migration safety — install.php must leave a genuinely fresh
 * database able to authenticate a customer, and must never silently apply
 * product-specific migrations while doing it.
 *
 * THE BUG (Phase 1.5 audit): install.php's step 2 only ever executed
 * db/schema.sql directly — it never touched db/migrations/ or the
 * `migrations` ledger at all. `contacts`/`identities` (the tables
 * Auth::attemptCustomerLogin() depends on) are created ONLY by
 * 0002_identity_core.php, which is not part of schema.sql. Net effect: a
 * brand-new install.php-based install has no `contacts` table, and the
 * first customer login throws an uncaught PDOException.
 *
 * THE FIX: install.php now applies exactly three migrations by name —
 * 0001_core_init (the same schema.sql content, now ledger-tracked),
 * 0002_identity_core (the missing identity spine), and 0011_login_attempts
 * (used by Auth's lockout logic) — via a new optional `$only` argument on
 * MigrationRunner::migrate(). Everything else (0003's seed — which carries
 * its own pre-apply review gate — 0004 content-revisions, 0005-0010 Studio,
 * 0012 content-builder) is deliberately left pending, not baselined, so a
 * later, explicit, product-specific install can still apply it correctly.
 *
 * This exercises the real MigrationRunner call install.php now makes,
 * against a genuinely fresh, empty, throwaway database created and dropped
 * within this test — NOT the shared integration test database, which is
 * already fully migrated and would prove nothing about a fresh install.
 */

declare(strict_types=1);

use Slate\Data\MigrationRunner;

/** Table names created by migrations that must NEVER run from install.php. */
const FIT_FORBIDDEN_TABLES = ['content_revisions', 'studio_contact_roles'];
/** Migrations install.php must apply, in order. */
const FIT_CORE_MIGRATIONS = ['0001_core_init', '0002_identity_core', '0011_login_attempts'];
/** Migrations install.php must leave untouched (pending). */
const FIT_MUST_STAY_PENDING = [
    '0003_seed_contacts', '0004_content_revisions',
    '0005_studio_core', '0006_studio_recitals', '0007_studio_fees',
    '0008_studio_announcements', '0009_studio_occurrence_note', '0010_studio_seasons',
    '0012_contentbuilder_draft_published',
];
/** Phase 1 installation migrations, including the tenant profile and identity. */
const FIT_PHASE1_MIGRATIONS = [
    '0001_core_init', '0002_identity_core', '0011_login_attempts',
    '0014_tenant_profiles', '0023_installation_identity',
];

/** Old (pre-fix) install.php behavior: exec db/schema.sql directly, nothing else. */
function fit_apply_schema_sql_only(\PDO $pdo): void {
    $schema = (string) file_get_contents(SLATE_ROOT . '/db/schema.sql');
    $stmts  = array_filter(array_map('trim', explode(';', preg_replace('/--[^\n]*\n/', "\n", $schema))));
    foreach ($stmts as $stmt) {
        if ($stmt !== '') $pdo->exec($stmt);
    }
}

function fit_table_exists(\PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

/** A throwaway database this test creates, uses, and drops. Never the shared test DB. */
function fit_fresh_pdo(string $dbName): \PDO {
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($dsn, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
    $dsn2 = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ";dbname=$dbName;charset=" . DB_CHARSET;
    return new \PDO($dsn2, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
}

function fit_drop(string $dbName): void {
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $dsn  = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    (new \PDO($dsn, DB_USER, DB_PASS))->exec("DROP DATABASE IF EXISTS `$dbName`");
}

/** Run an installation-service probe against the throwaway PDO, then restore the app connection. */
function fit_with_database_pdo(\PDO $pdo, callable $fn): mixed {
    $property = new \ReflectionProperty(\Slate\Data\Database::class, 'pdo');
    $property->setAccessible(true);
    $previous = $property->getValue();
    $property->setValue(null, $pdo);
    try {
        return $fn();
    } finally {
        $property->setValue(null, $previous);
    }
}

unit('fresh install: schema.sql alone (the pre-fix behavior) does not create contacts/identities', function (): void {
    $dbName = 'slate_freshinstall_old_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        fit_apply_schema_sql_only($pdo);
        assert_true(fit_table_exists($pdo, 'customers'), 'sanity: schema.sql itself must still create customers');
        assert_false(fit_table_exists($pdo, 'contacts'), 'BUG: schema.sql alone leaves contacts missing');
        assert_false(fit_table_exists($pdo, 'identities'), 'BUG: schema.sql alone leaves identities missing');
    } finally {
        fit_drop($dbName);
    }
});

unit('fresh install: the fixed migration call creates the identity spine and login_attempts', function (): void {
    $dbName = 'slate_freshinstall_new_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $applied = $runner->migrate(FIT_CORE_MIGRATIONS);

        assert_eq(FIT_CORE_MIGRATIONS, $applied, 'must apply exactly the three core migrations, in order');
        assert_true(fit_table_exists($pdo, 'customers'), 'core schema (0001) still runs');
        assert_true(fit_table_exists($pdo, 'contacts'), 'identity spine (0002) now runs');
        assert_true(fit_table_exists($pdo, 'contact_emails'));
        assert_true(fit_table_exists($pdo, 'contact_phones'));
        assert_true(fit_table_exists($pdo, 'identities'));
        assert_true(fit_table_exists($pdo, 'identity_tokens'));
        assert_true(fit_table_exists($pdo, 'login_attempts'), 'login throttling (0011) now runs');

        // A customer-auth-relevant query must be reachable without a schema error —
        // stands in for "the first customer login no longer throws uncaught".
        $pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn();

        foreach (FIT_FORBIDDEN_TABLES as $table) {
            assert_false(fit_table_exists($pdo, $table), "product-specific migration must not run ($table)");
        }

        $status = $runner->status();
        foreach (FIT_MUST_STAY_PENDING as $name) {
            assert_true(array_key_exists($name, $status), "migration $name must still be discoverable");
            assert_false($status[$name], "migration $name must remain pending, not applied");
        }
        foreach (FIT_CORE_MIGRATIONS as $name) {
            assert_true($status[$name] ?? false, "migration $name must be recorded as applied");
        }
    } finally {
        fit_drop($dbName);
    }
});

unit('fresh install: re-running the same migration call is idempotent (safe to resubmit step 2)', function (): void {
    $dbName = 'slate_freshinstall_rerun_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $first  = $runner->migrate(FIT_CORE_MIGRATIONS);
        assert_eq(FIT_CORE_MIGRATIONS, $first);

        // Simulates a double-submitted install.php step 2 (browser back/resubmit)
        // before the .installed marker is written in step 3.
        $second = $runner->migrate(FIT_CORE_MIGRATIONS);
        assert_eq([], $second, 'nothing new to apply — must not throw, must not duplicate ledger rows');

        $ledgerCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM migrations WHERE migration IN ('0001_core_init','0002_identity_core','0011_login_attempts')"
        )->fetchColumn();
        assert_eq(3, $ledgerCount, 'each migration recorded exactly once despite two calls');

        assert_true(fit_table_exists($pdo, 'contacts'), 'tables still present, not dropped/recreated');
    } finally {
        fit_drop($dbName);
    }
});

unit('phase 1: fresh install provisions one tenant, profile, identity, and first admin', function (): void {
    $dbName = 'slate_phase1_install_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        assert_eq(FIT_PHASE1_MIGRATIONS, $runner->migrate(FIT_PHASE1_MIGRATIONS));

        $result = fit_with_database_pdo($pdo, static fn (): array =>
            \Slate\Services\Installation\InstallationService::provision(
                'Phase One Owner',
                'phase1-owner@example.test',
                password_hash('phase1-password', PASSWORD_DEFAULT)
            )
        );

        assert_true($result['tenant_id'] > 0, 'tenant ID must come from the database');
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_profiles')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM installation_identity')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $admin = $pdo->query("SELECT tenant_id, role_id FROM users WHERE email = 'phase1-owner@example.test'")->fetch();
        assert_eq($result['tenant_id'], (int) $admin['tenant_id'], 'first admin must belong to the generated tenant');
        assert_eq(1, (int) $admin['role_id'], 'first admin must retain the Super Admin role');

        $identity = $pdo->query('SELECT tenant_id, installation_id FROM installation_identity WHERE singleton_id = 1')->fetch();
        assert_eq($result['tenant_id'], (int) $identity['tenant_id']);
        assert_true((bool) preg_match('/^[a-f0-9]{32}$/', (string) $identity['installation_id']));
    } finally {
        fit_drop($dbName);
    }
});

unit('phase 1: provisioning can be safely retried without duplicate records', function (): void {
    $dbName = 'slate_phase1_retry_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        [$first, $second] = fit_with_database_pdo($pdo, static function (): array {
            $first = \Slate\Services\Installation\InstallationService::provision(
                'Retry Owner',
                'retry-owner@example.test',
                password_hash('retry-password', PASSWORD_DEFAULT)
            );
            $second = \Slate\Services\Installation\InstallationService::provision(
                'Retry Owner',
                'retry-owner@example.test',
                password_hash('different-password-is-ignored', PASSWORD_DEFAULT)
            );
            return [$first, $second];
        });

        assert_eq($first['tenant_id'], $second['tenant_id']);
        assert_eq($first['installation_id'], $second['installation_id']);
        assert_eq($first['user_id'], $second['user_id']);
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_profiles')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM installation_identity')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    } finally {
        fit_drop($dbName);
    }
});

unit('QA Fix Round 2 (Fix 4): a well-formed INSTALLATION_ID already in .env is reused verbatim when the DB identity row is absent', function (): void {
    $dbName = 'slate_phase1_envreuse_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    $had = array_key_exists('INSTALLATION_ID', $_ENV);
    $old = $_ENV['INSTALLATION_ID'] ?? null;
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        $preExisting = bin2hex(random_bytes(16));
        $_ENV['INSTALLATION_ID'] = $preExisting;

        $result = fit_with_database_pdo($pdo, static fn (): array =>
            \Slate\Services\Installation\InstallationService::provision(
                'Env Reuse Owner',
                'env-reuse-owner@example.test',
                password_hash('env-reuse-password', PASSWORD_DEFAULT)
            )
        );

        assert_eq($preExisting, $result['installation_id'], 'the .env value must be reused verbatim, never regenerated');
        $stored = (string) $pdo->query('SELECT installation_id FROM installation_identity WHERE singleton_id = 1')->fetchColumn();
        assert_eq($preExisting, $stored, 'the exact .env value must be persisted into installation_identity');
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM installation_identity')->fetchColumn());

        // Repeated provisioning (retried step 2) stays stable: the DB row now
        // exists, so it — not .env — is authoritative on the next call, even
        // if .env somehow changed underneath it.
        $_ENV['INSTALLATION_ID'] = bin2hex(random_bytes(16));
        $second = fit_with_database_pdo($pdo, static fn (): array =>
            \Slate\Services\Installation\InstallationService::provision(
                'Env Reuse Owner',
                'env-reuse-owner@example.test',
                password_hash('env-reuse-password', PASSWORD_DEFAULT)
            )
        );
        assert_eq($preExisting, $second['installation_id'], 'once a DB row exists it remains authoritative, not a changed .env value');
    } finally {
        if ($had) { $_ENV['INSTALLATION_ID'] = $old; } else { unset($_ENV['INSTALLATION_ID']); }
        fit_drop($dbName);
    }
});

unit('QA Fix Round 2 (Fix 4): a malformed INSTALLATION_ID in .env is never trusted -- provisioning falls back to generating a fresh valid identity instead', function (): void {
    $dbName = 'slate_phase1_envmalformed_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    $had = array_key_exists('INSTALLATION_ID', $_ENV);
    $old = $_ENV['INSTALLATION_ID'] ?? null;
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        foreach (['not-32-hex-chars', strtoupper(bin2hex(random_bytes(16))), '  ' . bin2hex(random_bytes(16)), ''] as $badEnvValue) {
            $pdo->exec('DELETE FROM installation_identity');
            $pdo->exec('DELETE FROM users');
            $pdo->exec('DELETE FROM roles');
            $pdo->exec('DELETE FROM tenant_profiles');
            $pdo->exec('DELETE FROM tenants');
            $_ENV['INSTALLATION_ID'] = $badEnvValue;

            $result = fit_with_database_pdo($pdo, static fn (): array =>
                \Slate\Services\Installation\InstallationService::provision(
                    'Env Malformed Owner',
                    'env-malformed-owner@example.test',
                    password_hash('env-malformed-password', PASSWORD_DEFAULT)
                )
            );

            assert_true(
                (bool) preg_match('/^[a-f0-9]{32}$/', $result['installation_id']),
                'a malformed .env value (' . var_export($badEnvValue, true) . ') must never propagate into the resolved identity'
            );
            assert_true($result['installation_id'] !== $badEnvValue);
            $stored = (string) $pdo->query('SELECT installation_id FROM installation_identity WHERE singleton_id = 1')->fetchColumn();
            assert_eq($result['installation_id'], $stored, 'the freshly generated (not the malformed) value must be what is persisted');
        }
    } finally {
        if ($had) { $_ENV['INSTALLATION_ID'] = $old; } else { unset($_ENV['INSTALLATION_ID']); }
        fit_drop($dbName);
    }
});

unit('phase 1: an existing role from another tenant is never silently reassigned', function (): void {
    $dbName = 'slate_phase1_role_safety_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);
        $pdo->exec("UPDATE roles SET tenant_id = 999 WHERE id = 1");

        assert_throws(\RuntimeException::class, static function () use ($pdo): void {
            fit_with_database_pdo($pdo, static fn (): array =>
                \Slate\Services\Installation\InstallationService::provision(
                    'Unsafe Role Owner',
                    'unsafe-role@example.test',
                    password_hash('unsafe-role-password', PASSWORD_DEFAULT)
                )
            );
        }, 'inconsistent role ownership must stop installation');

        assert_eq(999, (int) $pdo->query('SELECT tenant_id FROM roles WHERE id = 1')->fetchColumn());
        assert_eq(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'failed provisioning must not create an admin');
    } finally {
        fit_drop($dbName);
    }
});

// ── Phase 5 (client installer rebuild): provisionCore() / createAdminAccount() ──
//
// The target installer sequence (docs/02-architecture/05-INSTALLATION-
// ACTIVATION.md §1) splits what provision() used to do atomically: Step 2
// ("Install Application") must reach a fully scaffolded, unlicensed,
// admin-less state, and Step 6 ("Create Admin Account") must only be able
// to add the admin afterward, never before. provision() itself is
// unchanged (tests above still exercise it byte-for-byte); these tests
// cover the two new methods it is now composed from.

unit('Phase 5: provisionCore() scaffolds tenant/profile/role/installation-identity with NO admin', function (): void {
    $dbName = 'slate_phase5_core_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        $core = fit_with_database_pdo($pdo, static fn (): array =>
            \Slate\Services\Installation\InstallationService::provisionCore()
        );

        assert_true($core['tenant_id'] > 0);
        assert_true((bool) preg_match('/^[a-f0-9]{32}$/', $core['installation_id']));
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenant_profiles')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM installation_identity')->fetchColumn());
        // db/schema.sql seeds 4 default system roles (Super Admin, Manager,
        // Editor, Viewer) unconditionally as part of the core schema itself
        // (0001_core_init) — Step 2's own job is only to confirm/resolve the
        // Super Admin row (id=1) against the newly-scaffolded tenant, not to
        // create the roles table's seed data, which already exists by then.
        $superAdmin = $pdo->query("SELECT tenant_id, slug, is_system FROM roles WHERE id = 1")->fetch();
        assert_eq($core['tenant_id'], (int) $superAdmin['tenant_id'], 'the seeded Super Admin role must belong to the scaffolded tenant');
        assert_eq('super-admin', $superAdmin['slug']);
        assert_eq(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no admin account until a license activates (INST-04)');
    } finally {
        fit_drop($dbName);
    }
});

unit('Phase 5: provisionCore() is safely retryable and reuses the same installation_id', function (): void {
    $dbName = 'slate_phase5_core_retry_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        [$first, $second] = fit_with_database_pdo($pdo, static function (): array {
            return [
                \Slate\Services\Installation\InstallationService::provisionCore(),
                \Slate\Services\Installation\InstallationService::provisionCore(),
            ];
        });

        assert_eq($first['tenant_id'], $second['tenant_id']);
        assert_eq($first['installation_id'], $second['installation_id']);
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM installation_identity')->fetchColumn());
    } finally {
        fit_drop($dbName);
    }
});

unit('Phase 5: createAdminAccount() adds the admin onto an already-scaffolded (provisionCore()\'d) tenant, and fills in the owner name/email', function (): void {
    $dbName = 'slate_phase5_admin_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        [$core, $userId] = fit_with_database_pdo($pdo, static function (): array {
            $core = \Slate\Services\Installation\InstallationService::provisionCore();
            $userId = \Slate\Services\Installation\InstallationService::createAdminAccount(
                $core['tenant_id'],
                'Phase Five Owner',
                'phase5-owner@example.test',
                password_hash('phase5-password', PASSWORD_DEFAULT)
            );
            return [$core, $userId];
        });

        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $admin = $pdo->query("SELECT tenant_id, role_id FROM users WHERE email = 'phase5-owner@example.test'")->fetch();
        assert_eq($core['tenant_id'], (int) $admin['tenant_id']);
        assert_eq(1, (int) $admin['role_id']);

        $profile = $pdo->query('SELECT owner_name, owner_email FROM tenant_profiles WHERE tenant_id = ' . (int) $core['tenant_id'])->fetch();
        assert_eq('Phase Five Owner', $profile['owner_name'], 'admin creation must fill in the owner name left blank at Step 2');
        assert_eq('phase5-owner@example.test', $profile['owner_email']);
    } finally {
        fit_drop($dbName);
    }
});

unit('Phase 5: createAdminAccount() is idempotent on retry (Scenario E — admin creation retried after a failure must not create a second admin)', function (): void {
    $dbName = 'slate_phase5_admin_retry_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        fit_with_database_pdo($pdo, static function () {
            $core = \Slate\Services\Installation\InstallationService::provisionCore();
            $first = \Slate\Services\Installation\InstallationService::createAdminAccount(
                $core['tenant_id'], 'Retry Admin', 'retry-admin@example.test',
                password_hash('first-password', PASSWORD_DEFAULT)
            );
            $second = \Slate\Services\Installation\InstallationService::createAdminAccount(
                $core['tenant_id'], 'Retry Admin', 'retry-admin@example.test',
                password_hash('different-password-is-ignored', PASSWORD_DEFAULT)
            );
            assert_eq($first, $second, 'retrying with the same tenant/email must resolve to the same admin row');
        });

        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'exactly one admin despite two calls');
    } finally {
        fit_drop($dbName);
    }
});

unit('Phase 5: provision() (the legacy atomic path) still behaves byte-for-byte as before the split', function (): void {
    $dbName = 'slate_phase5_provision_unchanged_' . slate_test_ns();
    $pdo = fit_fresh_pdo($dbName);
    try {
        $runner = new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations');
        $runner->migrate(FIT_PHASE1_MIGRATIONS);

        $result = fit_with_database_pdo($pdo, static fn (): array =>
            \Slate\Services\Installation\InstallationService::provision(
                'Atomic Owner', 'atomic-owner@example.test', password_hash('atomic-password', PASSWORD_DEFAULT)
            )
        );

        assert_true($result['tenant_id'] > 0);
        assert_true((bool) preg_match('/^[a-f0-9]{32}$/', $result['installation_id']));
        assert_true($result['user_id'] > 0);
        assert_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $profile = $pdo->query('SELECT owner_name FROM tenant_profiles WHERE tenant_id = ' . (int) $result['tenant_id'])->fetch();
        assert_eq('Atomic Owner', $profile['owner_name']);
    } finally {
        fit_drop($dbName);
    }
});
