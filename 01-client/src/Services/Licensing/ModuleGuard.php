<?php
/**
 * Slate — Module Entitlement Guard (Phase 7).
 *
 * docs/02-architecture/07-MODULE-GUARD-ARCHITECTURE.md §3: a thin,
 * throwing wrapper around the already-existing, already-tested
 * EntitlementService::canAccess() — this class adds no second licensing
 * authority and no second entitlement source. It is the missing CALLER;
 * EntitlementService already resolves remote-vs-legacy authority and the
 * plugin-active check correctly (07 §1: zero calls to canAccess() existed
 * anywhere in plugins/forms|membership|booking/ before this phase).
 *
 * Relationship to the Global License Guard (includes/license_guard.php,
 * Phase 6): that Guard answers one binary question — "does this
 * installation have ANY valid license at all" — for every request, from a
 * single config.php-level choke point. This class answers a second,
 * independent, narrower question, only for routes belonging to an
 * OPTIONAL module (forms/membership/booking, never Core): "does the
 * current license's entitlement set include THIS module." Both guards
 * must pass; neither replaces the other (04-ENTITLEMENT-ARCHITECTURE.md
 * §2, 07 §1).
 *
 * Response shape by access path (07 §4), one method per surface:
 *   require()       — admin controller / customer portal: HTTP 403,
 *                      rendered "not included in your license" page.
 *   requirePublic()  — anonymous public route: HTTP 404, not 403 —
 *                      matches the Central Server's own anti-enumeration
 *                      convention rather than confirming a disabled
 *                      feature exists.
 *   requireApi()     — /api/v1 route: HTTP 403 with a machine-readable
 *                      JSON error body via the existing ApiRouter
 *                      response convention.
 *   allows()         — boolean, non-throwing. Used both by the above
 *                      three (their shared "should this proceed" check)
 *                      and directly by background/cron listeners and
 *                      webhook effect-guards, which have no HTTP response
 *                      to render and must instead simply skip their
 *                      module-specific side effect (07 §5).
 *   isEntitled()     — boolean, PURE (no test bypass — see below),
 *                      presentational use only (menu items, dashboard
 *                      widgets). Never a substitute for the enforcement
 *                      methods above at an actual entry point (07 §3).
 *
 * Test/migration bypass: require()/requirePublic()/requireApi()/allows()
 * short-circuit to "proceed" under the exact same, non-request-
 * controllable conditions the Global License Guard already uses
 * (SLATE_TESTING/SLATE_MIGRATING/APP_ENV=testing, or CLI without an HTTP
 * request context — 06-GLOBAL-LICENSE-GUARD.md §3a, D19 LOCKED). This is
 * deliberate, not scope creep: every existing Forms/Membership/Booking
 * integration test (e.g. BookingAdminCapabilityGuardTest.php) exercises
 * real admin/public entry points via a shelled-out child process that
 * defines SLATE_TESTING by default and runs against a CI database with no
 * LICENSE_SERVER_URL/LICENSE_KEY configured — EntitlementService's
 * authorityMode() there is 'unconfigured', which canAccess() correctly
 * treats as "not entitled" (fail-closed). Without this bypass, adding this
 * class's calls to every module entry point would 403 the entire existing
 * Forms/Membership/Booking suite, which is a Phase 7 regression, not a
 * Phase 7 security improvement — REQUIREMENTS item 17/18 requires existing
 * functionality and existing tests to keep working. A test that wants to
 * exercise REAL enforcement opts in with the same SLATE_LICENSE_GUARD_LIVE=1
 * env var the shared tests/fixtures/*-probe.php fixtures already read to
 * decide whether to define SLATE_TESTING (see ModuleGuardTest.php) — no
 * new bypass mechanism, no new fixture changes, the exact existing Phase 6
 * convention extended to cover this guard too, since both guards are only
 * ever meaningfully live or bypassed together for a single simulated
 * request. isEntitled() itself is deliberately NOT bypassed: it is the
 * real, presentational answer EntitlementService gives, used by code that
 * wants to know actual state (e.g. a future admin UI badge), not by code
 * deciding whether to allow a request through.
 */

declare(strict_types=1);

namespace Slate\Services\Licensing;

final class ModuleGuard
{
    /**
     * The real, non-bypassed entitlement answer. Presentational use only
     * (conditional UI rendering) — never call this to decide whether to
     * let a request proceed; use require()/requirePublic()/requireApi()/
     * allows() for that (07 §3).
     */
    public static function isEntitled(string $moduleKey): bool
    {
        return EntitlementService::canAccess(current_tenant_id(), $moduleKey);
    }

    /**
     * Boolean, non-throwing "should this proceed" check — bypass-aware.
     * The one call background/cron listeners and webhook effect-guards
     * use directly, since they have no HTTP response to render and must
     * instead just skip their module-specific side effect (07 §5).
     */
    public static function allows(string $moduleKey): bool
    {
        if (self::isBypassed()) return true;
        return self::isEntitled($moduleKey);
    }

    /** Admin controller entry point / customer portal. HTTP 403, rendered page. */
    public static function require(string $moduleKey): void
    {
        if (self::allows($moduleKey)) return;
        self::respondAdminBlocked();
    }

    /** Anonymous public route (e.g. /forms/<slug>, /book, /member/membership/*). HTTP 404. */
    public static function requirePublic(string $moduleKey): void
    {
        if (self::allows($moduleKey)) return;
        self::respondPublicBlocked();
    }

    /** /api/v1/<module> route. HTTP 403 with a machine-readable JSON error body. */
    public static function requireApi(string $moduleKey): void
    {
        if (self::allows($moduleKey)) return;
        self::respondApiBlocked($moduleKey);
    }

    // ── Bypass detection (mirrors includes/license_guard.php's §3a bypass) ──

    private static function isBypassed(): bool
    {
        if (\PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            return true;
        }
        if (\defined('SLATE_TESTING') || \defined('SLATE_MIGRATING')) {
            return true;
        }
        return \function_exists('env') && env('APP_ENV') === 'testing';
    }

    // ── Response rendering per surface (07 §4) ───────────────────────────

    private static function respondAdminBlocked(): void
    {
        if (\function_exists('slate_render_error')) {
            slate_render_error(
                403,
                'Module not available',
                'This module is not included in your current license. Please contact your provider.'
            );
        } else {
            \http_response_code(403);
            echo 'This module is not included in your current license.';
        }
        exit;
    }

    private static function respondPublicBlocked(): void
    {
        // 404, not 403 — deliberately indistinguishable from a route that
        // does not exist at all, so an anonymous visitor cannot use this
        // response to learn that a disabled feature exists (07 §4).
        if (\function_exists('slate_render_error')) {
            slate_render_error(404, 'Not found', 'The page you are looking for could not be found.');
        } else {
            \http_response_code(404);
            \header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
        }
        exit;
    }

    private static function respondApiBlocked(string $moduleKey): void
    {
        if (\class_exists('\Slate\Kernel\Http\ApiRouter')) {
            \Slate\Kernel\Http\ApiRouter::respondError(
                'This module is not included in your current license.',
                'MODULE_NOT_ENTITLED',
                403,
                ['module' => $moduleKey]
            );
            return; // respondError() already exits
        }
        \http_response_code(403);
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode(['ok' => false, 'error' => 'module_not_entitled', 'module' => $moduleKey]);
        exit;
    }
}
