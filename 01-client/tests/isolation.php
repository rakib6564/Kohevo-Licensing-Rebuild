<?php
/**
 * Per-checkout test isolation.
 *
 * The integration suite writes real rows: synthetic tenants, login attempts,
 * auth tokens. Those identifiers used to be hardcoded, which is fine for one
 * checkout and wrong for two. With a second worktree running the same suite
 * against the same database, both sessions write tenant 918100 and both delete
 * it in teardown — so each one's rows vanish mid-test and the failures land on
 * whichever suite happened to be slower. That reads as flakiness, not as a
 * collision, which is the expensive part.
 *
 * Every shared identifier is therefore namespaced by checkout.
 *
 * The namespace is DERIVED FROM THE REPOSITORY PATH by default. Requiring an
 * environment variable would mean the isolation is absent exactly when someone
 * forgets it, which is the same failure it exists to prevent. Set
 * SLATE_TEST_NS to override (CI pins 0 so its identifiers stay stable and
 * greppable in logs).
 *
 * Isolation is by identifier, not by database: one schema, disjoint rows. A
 * separate database per worktree would isolate more, at the cost of a second
 * migration target that then has to be kept in step.
 *
 * Pure: no config.php, no database. Safe for the unit suite, which boots
 * neither.
 */

declare(strict_types=1);

if (!function_exists('slate_test_ns')) {
    /**
     * Stable namespace for this checkout, 0..63.
     *
     * Deterministic for a given path, so identifiers are reproducible across
     * runs — a test that fails here fails with the same tenant id next time.
     */
    function slate_test_ns(): int
    {
        static $ns = null;
        if ($ns !== null) return $ns;

        $env = getenv('SLATE_TEST_NS');
        if ($env !== false && $env !== '' && ctype_digit((string) $env)) {
            return $ns = ((int) $env) % 64;
        }

        $root = dirname(__DIR__);
        return $ns = (int) (crc32(realpath($root) ?: $root) % 64);
    }
}

if (!function_exists('slate_test_tenant')) {
    /**
     * Namespace a synthetic tenant id.
     *
     * Keeps the original number legible in the result — 918100 in checkout 7
     * becomes 7918100 — so a stray row is still traceable to the test that
     * wrote it. Stays inside INT UNSIGNED for every namespace.
     */
    function slate_test_tenant(int $localId): int
    {
        return $localId + slate_test_ns() * 1000000;
    }
}

if (!function_exists('slate_test_ip')) {
    /**
     * Namespace a test IP address.
     *
     * login_attempts is keyed by (scope, ip) and carries no tenant scope, so
     * tenant namespacing alone would still let two checkouts lock each other
     * out. The last two octets of the original are preserved so distinct test
     * addresses stay distinct within a namespace.
     */
    function slate_test_ip(string $base): string
    {
        $parts = explode('.', $base);
        $c = isset($parts[2]) ? ((int) $parts[2]) % 256 : 0;
        $d = isset($parts[3]) ? ((int) $parts[3]) % 256 : 1;
        return sprintf('10.%d.%d.%d', slate_test_ns(), $c, $d);
    }
}

if (!function_exists('slate_test_ns_banner')) {
    /** One line at suite start, so a collision is diagnosable from the log alone. */
    function slate_test_ns_banner(): string
    {
        return '# test namespace ' . slate_test_ns()
             . ' (tenants +' . (slate_test_ns() * 1000000) . ', ips 10.' . slate_test_ns() . '.x.x)';
    }
}
