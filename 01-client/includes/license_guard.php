<?php
/**
 * Slate — Global License Guard (Phase 6).
 *
 * The single, mandatory choke point docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md
 * §3 calls for (Option A, RECOMMENDED there, LOCKED for this implementation
 * per §15-PHASE-1-DECISIONS.md D5): one call from config.php, applied to
 * every entry point that boots the app, rather than a per-script opt-in —
 * the pattern that let every optional module ship with zero entitlement
 * enforcement in the first place (07-MODULE-GUARD-ARCHITECTURE.md §1).
 *
 * Reads ONLY the existing, already tamper-evident trust source
 * (Slate\Services\Licensing\SlateLicenseCacheStore::readTrustState(),
 * Phase 4/5) — this file adds no second license-trust mechanism. It adds
 * the missing caller that actually enforces what that trust state means at
 * every request-level entry point (06 §8, "Missing global middleware").
 *
 * Scope: the GLOBAL, binary "does this installation have any valid license
 * at all" decision only. Per-module entitlement enforcement
 * (07-MODULE-GUARD-ARCHITECTURE.md, Phase 7) is a separate, later phase —
 * this file does not implement it and does not call into it.
 */

declare(strict_types=1);

require_once __DIR__ . '/error_page.php';

if (!function_exists('slate_license_guard_state')) {
    /**
     * The Global Guard's own binary trust decision, independent of Auth and
     * independent of route. Fails closed on every ambiguous or
     * indeterminate case (06 §7, §11): a missing cache row, an untrusted
     * (installation-id-mismatched/malformed) row, a non-licensed status, or
     * a snapshot stale past the offline-tolerance window are all treated as
     * "no valid license" — never as "not yet configured, allow through",
     * which is the exact fail-open bug 06 §7 requires removing.
     *
     * Phase 9: the status / offline-tolerance / commercial expiry+grace
     * derivation lives in one pure place,
     * Slate\Services\Licensing\CommercialLicenseWindow::evaluate(), shared
     * with EntitlementService (module access) and LicenseStatusPresenter
     * (UI) so enforcement and presentation can never disagree about a
     * boundary. This function still owns the trust read and the fail-closed
     * wrapper; it adds no second lock mechanism. Access continues through
     * the pre-expiry warning and the 7-day commercial grace period
     * (08 §2) and locks at exactly expires_at + 7 days. warning_days /
     * grace_days are LOCKED at 7/7 (15-PHASE-1-DECISIONS.md) and are not
     * part of the signed payload this client persists, so they are
     * constants there; offline tolerance is its own separate constant
     * (08 §4) and is never combined with commercial grace.
     *
     * 'phase' (active | expiring_soon | grace | locked | null) is
     * informational for callers that present state; the Guard itself acts
     * only on 'locked'.
     *
     * @return array{locked:bool, reason:?string, phase:?string}
     */
    function slate_license_guard_state(): array
    {
        try {
            if (!class_exists('\Slate\Services\Licensing\SlateLicenseCacheStore')
                || !class_exists('\Slate\Services\Licensing\CommercialLicenseWindow')) {
                return ['locked' => true, 'reason' => 'unavailable', 'phase' => null];
            }
            $store = new \Slate\Services\Licensing\SlateLicenseCacheStore(current_tenant_id());

            // Server clock only — never a client-supplied time.
            $window = \Slate\Services\Licensing\CommercialLicenseWindow::evaluate($store->readTrustState(), time());

            return [
                'locked' => $window['allowed'] !== true,
                'reason' => $window['allowed'] === true ? null : (string) ($window['reason'] ?? 'error'),
                'phase'  => $window['phase'] ?? null,
            ];
        } catch (\Throwable $e) {
            // Fail closed on any inability to determine state at all (06 §7).
            return ['locked' => true, 'reason' => 'error', 'phase' => null];
        }
    }
}

if (!function_exists('slate_license_guard_current_path')) {
    /**
     * The physical script currently executing, lowercased and relative
     * (e.g. "admin/login.php", "api/v1.php") — read from SCRIPT_NAME, not
     * SCRIPT_FILENAME, so this agrees with what a real Apache request
     * reports for a rewritten route (public.php, api/v1.php,
     * customer/portal_router.php all execute as themselves regardless of
     * the pretty URL) AND with how this suite's own integration-test
     * fixtures (tests/fixtures/*-probe.php) simulate a request — they set
     * SCRIPT_NAME to the exact page under test, not SCRIPT_FILENAME (which
     * would remain the fixture's own path, since they reach the target via
     * `require`). Using SCRIPT_NAME is what makes this Guard's whitelist
     * and response-format logic exercise identically under a real request
     * and under those fixtures.
     */
    function slate_license_guard_current_path(): string
    {
        $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($path === '') return '';
        if (($q = strpos($path, '?')) !== false) $path = substr($path, 0, $q);
        return strtolower(ltrim(str_replace('\\', '/', $path), '/'));
    }
}

if (!function_exists('slate_license_guard_is_whitelisted')) {
    /**
     * The narrow, explicit whitelist from 06 §2 — everything NOT in this
     * list is subject to the Guard. Deliberately a short allow-list, not a
     * denylist (06 §2: "a deliberately short, explicit whitelist... rather
     * than a blocklist, because a blocklist silently fails open for
     * anything the implementer forgot to add").
     *
     * Static assets are not listed: they are served directly by the web
     * server, never execute this file, and the Guard must not newly
     * restrict them (06 §2). cron.php is listed here, not because its
     * *business* hooks (frequent_cron/daily_cron plugin listeners) are
     * exempt from licensing — per-module suppression of those is
     * 07-MODULE-GUARD-ARCHITECTURE.md §5's job, deliberately out of Phase 6
     * scope — but because cron.php already has its own independent,
     * secret-gated authorization (CRON_SECRET); letting the Guard 403 it
     * BEFORE that secret check runs would leak this installation's lock
     * state to an unauthenticated caller who does not even know the
     * secret, which is strictly worse than today.
     */
    function slate_license_guard_is_whitelisted(): bool
    {
        static $whitelist = [
            'install.php',       // 06 §2, §4 — self-protecting once .installed exists
            'cron.php',          // own CRON_SECRET-gated auth; see docblock above
            'admin/login.php',   // 06 §2 — must reach login to reach the recovery screen
            'admin/logout.php',  // 06 §2, D20 / Finding F-07
            'admin/license.php', // 06 §2, §9 — the License Locked recovery screen
        ];
        return in_array(slate_license_guard_current_path(), $whitelist, true);
    }
}

if (!function_exists('slate_license_guard_wants_json')) {
    /** Best-effort: choose a JSON body over the branded HTML page for API/AJAX callers. */
    function slate_license_guard_wants_json(): bool
    {
        if (str_starts_with(slate_license_guard_current_path(), 'api/')) return true;

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) return true;

        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}

if (!function_exists('slate_license_guard_respond_locked')) {
    function slate_license_guard_respond_locked(): void
    {
        if (str_starts_with(slate_license_guard_current_path(), 'api/')
            && class_exists('\Slate\Kernel\Http\ApiRouter')) {
            \Slate\Kernel\Http\ApiRouter::respondError(
                "This installation's license is not currently active.",
                'LICENSE_INACTIVE',
                403
            );
            return; // respondError() already exits
        }

        if (slate_license_guard_wants_json()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'license_inactive']);
            exit;
        }

        slate_render_error(403, 'License inactive',
            "This installation's license is not currently active. Please contact your provider.");
        exit;
    }
}

if (!function_exists('slate_license_guard')) {
    /**
     * The single mandatory choke point (06 §3, Option A). Called once, near
     * the end of config.php, after Database/Auth/PluginLoader::boot() are
     * all available — see that call site for why.
     */
    function slate_license_guard(): void
    {
        // Non-HTTP console execution (bin/*.php ops tooling, bin/migrate,
        // this suite's own dependency-free test runners) is a different
        // trust boundary — direct server/filesystem access, not remote
        // "deployment runtime traffic" (06 §3a's own framing). Detected by
        // the absence of any HTTP request context rather than PHP_SAPI
        // alone, because this suite's integration tests deliberately run
        // real entry points under CLI SAPI while setting $_SERVER to
        // simulate a genuine web request (tests/fixtures/*-probe.php) —
        // those MUST still be gated, or this Guard would be untestable
        // through this codebase's own established test-writing convention.
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            return;
        }

        // Explicit, non-request-controllable bypass for test/migration
        // bootstraps (06 §3a, D19 LOCKED). Defined only by this suite's own
        // trusted bootstrap scripts — never derived from request input, so
        // an attacker cannot set these by crafting a request.
        if (defined('SLATE_TESTING') || defined('SLATE_MIGRATING') || env('APP_ENV') === 'testing') {
            return;
        }

        if (slate_license_guard_is_whitelisted()) {
            return;
        }

        $state = slate_license_guard_state();
        if (!$state['locked']) {
            return;
        }

        slate_license_guard_respond_locked();
    }
}
