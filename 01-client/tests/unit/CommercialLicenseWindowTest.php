<?php
/**
 * Phase 9: CommercialLicenseWindow::evaluate() — the one pure derivation of
 * Active / Expiring soon / Grace / Locked (08-EXPIRY-GRACE-OFFLINE.md §2)
 * that the Global License Guard, EntitlementService and
 * LicenseStatusPresenter all consume. Every boundary is tested to the exact
 * second against a fixed clock, so nothing here depends on wall time.
 *
 * End-to-end enforcement of the same states through real entry points
 * lives in tests/integration/Phase9ExpiryGraceTest.php.
 */

declare(strict_types=1);

use Slate\Services\Licensing\CommercialLicenseWindow as CLW;
use Slate\Services\Licensing\LicenseStatusPresenter;

/** Fixed "expires_at" for every boundary test: 2026-10-01 12:00:00 UTC. */
const CLW_EXPIRES = '2026-10-01 12:00:00';
const CLW_DAY = 86400;

function clw_expires_ts(): int { return gmmktime(12, 0, 0, 10, 1, 2026); }

/** A trusted snapshot fetched at $now (fresh) unless overridden. */
function clw_trust(int $now, array $data = []): array {
    return ['found' => true, 'trusted' => true, 'data' => $data + [
        'status' => 'active', 'plan' => 'pro', 'entitlements' => ['forms'],
        'expires_at' => CLW_EXPIRES, 'fetched_at' => gmdate('Y-m-d H:i:s', $now),
        'installation_id' => str_repeat('a', 32),
    ]];
}

function clw_at(int $offsetFromExpiry, array $data = []): array {
    $now = clw_expires_ts() + $offsetFromExpiry;
    return CLW::evaluate(clw_trust($now, $data), $now);
}

// ── 1–9. Expiry boundaries (exact seconds) ──────────────────────────────

unit('Phase 9 window: 8 days before expiry is Active with no warning', function () {
    $w = clw_at(-8 * CLW_DAY);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_ACTIVE, $w['phase']);
});

unit('Phase 9 window: 7 days + 1 second before expiry is still Active (warning not yet begun)', function () {
    $w = clw_at(-7 * CLW_DAY - 1);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_ACTIVE, $w['phase']);
});

unit('Phase 9 window: exactly 7 days before expiry the warning begins', function () {
    $w = clw_at(-7 * CLW_DAY);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_EXPIRING_SOON, $w['phase']);
    assert_eq(7 * CLW_DAY, $w['seconds_until_expiry']);
});

unit('Phase 9 window: 6 days before expiry is Expiring soon', function () {
    $w = clw_at(-6 * CLW_DAY);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_EXPIRING_SOON, $w['phase']);
});

unit('Phase 9 window: 1 second before expiry is still Expiring soon, not grace', function () {
    $w = clw_at(-1);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_EXPIRING_SOON, $w['phase']);
    assert_eq(1, $w['seconds_until_expiry']);
});

unit('Phase 9 window: exactly at expires_at the license enters commercial grace (still allowed)', function () {
    $w = clw_at(0);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_GRACE, $w['phase']);
    assert_eq(7 * CLW_DAY, $w['grace_seconds_remaining']);
    assert_eq(clw_expires_ts() + 7 * CLW_DAY, $w['grace_ends_at']);
});

unit('Phase 9 window: 1 second into grace is allowed', function () {
    $w = clw_at(1);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_GRACE, $w['phase']);
});

unit('Phase 9 window: 6 days 23:59:59 into grace (grace end - 1s) is still allowed', function () {
    $w = clw_at(7 * CLW_DAY - 1);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_GRACE, $w['phase']);
    assert_eq(1, $w['grace_seconds_remaining']);
});

unit('Phase 9 window: exactly at expires_at + 7 days the application is locked', function () {
    $w = clw_at(7 * CLW_DAY);
    assert_false($w['allowed']);
    assert_eq('expired', $w['reason']);
    assert_eq(CLW::PHASE_LOCKED, $w['phase']);
});

unit('Phase 9 window: 1 second after grace end remains locked', function () {
    $w = clw_at(7 * CLW_DAY + 1);
    assert_false($w['allowed']);
    assert_eq('expired', $w['reason']);
});

unit('Phase 9 window: long after grace end remains locked', function () {
    assert_false(clw_at(365 * CLW_DAY)['allowed']);
});

// ── Signed status "expired" is the grace trigger (03 §1), not a lock ────

unit('Phase 9 window: a signed status "expired" inside the 7-day window is grace, not an immediate lock', function () {
    $w = clw_at(2 * CLW_DAY, ['status' => 'expired']);
    assert_true($w['allowed'], 'the Central Server flips status to expired at expires_at (LicenseService::syncExpiry); that must start grace, not lock');
    assert_eq(CLW::PHASE_GRACE, $w['phase']);
});

unit('Phase 9 window: a signed status "expired" past grace locks', function () {
    $w = clw_at(7 * CLW_DAY, ['status' => 'expired']);
    assert_false($w['allowed']);
    assert_eq('expired', $w['reason']);
});

unit('Phase 9 window: a signed status "expired" with no expires_at cannot be given grace and locks', function () {
    $now = clw_expires_ts();
    $w = CLW::evaluate(clw_trust($now, ['status' => 'expired', 'expires_at' => null]), $now);
    assert_false($w['allowed']);
});

unit('Phase 9 window: a signed status "expired" whose expires_at is still in the future is inconsistent and fails closed', function () {
    $w = clw_at(-3 * CLW_DAY, ['status' => 'expired']);
    assert_false($w['allowed']);
    assert_eq('malformed', $w['reason']);
});

unit('Phase 9 window: trial follows the same warning / grace / lock timeline as active', function () {
    assert_eq(CLW::PHASE_EXPIRING_SOON, clw_at(-CLW_DAY, ['status' => 'trial'])['phase']);
    assert_eq(CLW::PHASE_GRACE, clw_at(CLW_DAY, ['status' => 'trial'])['phase']);
    assert_false(clw_at(7 * CLW_DAY, ['status' => 'trial'])['allowed']);
});

unit('Phase 9 window: an active license with no expiry date is Active indefinitely (no warning, no grace)', function () {
    $now = clw_expires_ts();
    $w = CLW::evaluate(clw_trust($now, ['expires_at' => null]), $now);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_ACTIVE, $w['phase']);
    assert_null($w['expires_at']);
});

// ── 10–19. State precedence ──────────────────────────────────────────────

unit('Phase 9 precedence: suspended locks even far inside nominal expiry, and grace never rescues it', function () {
    foreach ([-30 * CLW_DAY, -CLW_DAY, CLW_DAY] as $offset) {
        $w = clw_at($offset, ['status' => 'suspended']);
        assert_false($w['allowed'], "suspended at offset $offset");
        assert_eq('suspended', $w['reason']);
        assert_null($w['phase'], 'an administrative lock is not a commercial-expiry phase');
    }
});

unit('Phase 9 precedence: revoked locks even far inside nominal expiry, and grace never rescues it', function () {
    foreach ([-30 * CLW_DAY, -CLW_DAY, CLW_DAY] as $offset) {
        $w = clw_at($offset, ['status' => 'revoked']);
        assert_false($w['allowed'], "revoked at offset $offset");
        assert_eq('revoked', $w['reason']);
    }
});

unit('Phase 9 precedence: cancelled and any unrecognised status lock', function () {
    foreach (['cancelled', 'unactivated', 'ACTIVE', 'active ', 'grace', 'unknown'] as $status) {
        $w = clw_at(-30 * CLW_DAY, ['status' => $status]);
        assert_false($w['allowed'], "status '$status' must lock");
    }
});

unit('Phase 9 precedence: untrusted (installation mismatch) locks regardless of an active-looking or in-grace payload', function () {
    $now = clw_expires_ts() + CLW_DAY;
    foreach ([null, ['status' => 'active', 'expires_at' => CLW_EXPIRES]] as $data) {
        $w = CLW::evaluate(['found' => true, 'trusted' => false, 'data' => $data], $now);
        assert_false($w['allowed']);
        assert_eq('untrusted', $w['reason']);
    }
});

unit('Phase 9 precedence: a missing cache row locks', function () {
    $w = CLW::evaluate(['found' => false, 'trusted' => false, 'data' => null], clw_expires_ts());
    assert_false($w['allowed']);
    assert_eq('missing', $w['reason']);
});

unit('Phase 9 precedence: malformed trusted data fails closed (bad status, fetched_at, expires_at)', function () {
    $now = clw_expires_ts() - 30 * CLW_DAY;
    foreach ([
        ['status' => null], ['status' => ''], ['status' => ['active']],
        ['fetched_at' => null], ['fetched_at' => 'yesterday'], ['fetched_at' => '2026-13-45 99:00:00'],
        ['expires_at' => 'not a date'], ['expires_at' => '0000-00-00 00:00:00'], ['expires_at' => '2026-02-31 00:00:00'],
        ['expires_at' => 12345], ['expires_at' => ['2030-01-01']],
    ] as $bad) {
        $w = CLW::evaluate(clw_trust($now, $bad), $now);
        assert_false($w['allowed'], 'malformed: ' . json_encode($bad));
        assert_eq('malformed', $w['reason'], 'malformed: ' . json_encode($bad));
    }
});

unit('Phase 9 precedence: an empty-string expires_at means "no expiry", never a parse of the empty string', function () {
    $now = clw_expires_ts();
    $w = CLW::evaluate(clw_trust($now, ['expires_at' => '']), $now);
    assert_true($w['allowed']);
    assert_eq(CLW::PHASE_ACTIVE, $w['phase']);
});

// ── Commercial grace vs offline tolerance (08 §1, §4) ───────────────────

unit('Phase 9 separation: a stale snapshot locks even though the license has not expired (no "offline grace")', function () {
    $now = clw_expires_ts() - 30 * CLW_DAY;
    $w = CLW::evaluate(clw_trust($now, ['fetched_at' => gmdate('Y-m-d H:i:s', $now - 7 * CLW_DAY - 1)]), $now);
    assert_false($w['allowed']);
    assert_eq('stale', $w['reason']);
});

unit('Phase 9 separation: a stale snapshot inside commercial grace still locks — grace never extends offline tolerance', function () {
    $now = clw_expires_ts() + CLW_DAY;
    $w = CLW::evaluate(clw_trust($now, ['fetched_at' => gmdate('Y-m-d H:i:s', $now - 8 * CLW_DAY)]), $now);
    assert_false($w['allowed']);
    assert_eq('stale', $w['reason']);
});

unit('Phase 9 separation: a fresh snapshot past grace locks — recent contact never extends commercial expiry', function () {
    $now = clw_expires_ts() + 7 * CLW_DAY;
    $w = CLW::evaluate(clw_trust($now, ['fetched_at' => gmdate('Y-m-d H:i:s', $now)]), $now);
    assert_false($w['allowed']);
    assert_eq('expired', $w['reason']);
});

unit('Phase 9 separation: a snapshot fetched long before expiry does not move the grace boundary', function () {
    // Fetched 6 days before now; now is 6 days into grace. Grace still ends
    // at expires_at + 7d, never at fetched_at + anything.
    $now = clw_expires_ts() + 6 * CLW_DAY;
    $w = CLW::evaluate(clw_trust($now, ['fetched_at' => gmdate('Y-m-d H:i:s', $now - 6 * CLW_DAY)]), $now);
    assert_true($w['allowed']);
    assert_eq(clw_expires_ts() + 7 * CLW_DAY, $w['grace_ends_at']);
});

unit('Phase 9 separation: the two windows are distinct named constants', function () {
    $ref = new ReflectionClass(CLW::class);
    assert_true($ref->hasConstant('GRACE_SECONDS') && $ref->hasConstant('OFFLINE_TOLERANCE_SECONDS') && $ref->hasConstant('WARNING_SECONDS'));
    assert_eq(7 * CLW_DAY, CLW::GRACE_SECONDS);
    assert_eq(7 * CLW_DAY, CLW::WARNING_SECONDS);
});

// ── Time handling ────────────────────────────────────────────────────────

unit('Phase 9 time: DATETIME strings are read as UTC regardless of the process timezone', function () {
    $prev = date_default_timezone_get();
    try {
        date_default_timezone_set('America/New_York');
        assert_eq(clw_expires_ts(), CLW::parseUtc(CLW_EXPIRES));
        date_default_timezone_set('Asia/Kolkata');
        assert_eq(clw_expires_ts(), CLW::parseUtc(CLW_EXPIRES));
        $w = CLW::evaluate(clw_trust(clw_expires_ts()), clw_expires_ts());
        assert_eq(CLW::PHASE_GRACE, $w['phase'], 'the boundary does not shift with date.timezone');
    } finally {
        date_default_timezone_set($prev);
    }
});

unit('Phase 9 time: explicit offsets are honoured; relative phrases are rejected', function () {
    assert_eq(clw_expires_ts(), CLW::parseUtc('2026-10-01T14:00:00+02:00'));
    assert_eq(clw_expires_ts(), CLW::parseUtc('2026-10-01T12:00:00Z'));
    foreach (['now', '+1 year', 'tomorrow', 'next monday', '@1790000000', '2026-10-01 12:00:00 +1 year'] as $relative) {
        assert_null(CLW::parseUtc($relative), "relative/unsupported date '$relative' must not parse");
    }
});

// ── 20, 28, 29. Tampering ────────────────────────────────────────────────

unit('Phase 9 security: the evaluator takes the server clock only — stray "now"/grace keys in the data are ignored', function () {
    $now = clw_expires_ts() + 7 * CLW_DAY; // locked
    $w = CLW::evaluate(clw_trust($now, [
        'now' => gmdate('Y-m-d H:i:s', clw_expires_ts() - 30 * CLW_DAY),
        'grace' => true, 'grace_days' => 365, 'grace_ends_at' => '2099-01-01 00:00:00',
        'warning_days' => 0, 'license_status' => 'active', 'phase' => 'active', 'locked' => false,
    ]), $now);
    assert_false($w['allowed'], 'no stored or injected grace value can extend the window — grace is derived, never read');
    assert_eq('expired', $w['reason']);
});

unit('Phase 9 security: an untrusted row with a far-future expires_at is still locked (tampered expiry never trusted)', function () {
    $w = CLW::evaluate(['found' => true, 'trusted' => false, 'data' => [
        'status' => 'active', 'expires_at' => '2099-01-01 00:00:00', 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]], time());
    assert_false($w['allowed']);
    assert_eq('untrusted', $w['reason']);
});

// ── Presenter (Phase 8 view model, Phase 9 states) ──────────────────────

function clw_view(int $offset, array $data = [], ?array $guard = null): array {
    $now = clw_expires_ts() + $offset;
    $trust = clw_trust($now, $data);
    $guard ??= (static function () use ($trust, $now): array {
        $w = CLW::evaluate($trust, $now);
        return ['locked' => !$w['allowed'], 'reason' => $w['reason']];
    })();
    return LicenseStatusPresenter::fromInputs($trust, $guard, static fn(string $k): array => [
        'entitled' => $k === 'forms', 'licensed' => $k === 'forms',
    ], 'remote', $now);
}

unit('Phase 9 presenter: Active shows no banner and no countdown', function () {
    $v = clw_view(-8 * CLW_DAY);
    assert_eq('active', $v['state']);
    assert_null($v['banner']);
    assert_null($v['time_remaining']);
});

unit('Phase 9 presenter: Expiring soon shows an exact UTC expiry and a warning banner', function () {
    $v = clw_view(-3 * CLW_DAY);
    assert_eq('expiring_soon', $v['state']);
    assert_eq('Expiring soon', $v['label']);
    assert_eq('warning', $v['tone']);
    assert_false($v['locked']);
    assert_eq('Oct 1, 2026 12:00 UTC', $v['expires_label']);
    assert_eq('3 days', $v['time_remaining']);
    assert_eq('expiring_soon', $v['banner']['phase']);
    assert_true(str_contains($v['banner']['message'], 'Oct 1, 2026 12:00 UTC'));
    assert_true(str_contains($v['banner']['message'], 'renew'));
    assert_eq('enabled', array_column($v['modules'], 'state', 'key')['forms']);
});

unit('Phase 9 presenter: Grace says expired (never "active"), gives expiry, grace end and remaining time, and never blames the network', function () {
    $v = clw_view(CLW_DAY + 3600);
    assert_eq('grace', $v['state']);
    assert_eq('Expired — grace period', $v['label']);
    assert_false($v['locked']);
    assert_eq('Oct 8, 2026 12:00 UTC', $v['grace_ends_label']);
    assert_eq('5 days 23 hours', $v['time_remaining']);
    $text = $v['label'] . ' ' . $v['summary'] . ' ' . $v['banner']['title'] . ' ' . $v['banner']['message'];
    assert_true(str_contains($v['banner']['message'], 'expired on Oct 1, 2026 12:00 UTC'));
    assert_true(str_contains($v['banner']['message'], 'Oct 8, 2026 12:00 UTC'));
    assert_true(str_contains($v['banner']['message'], '5 days 23 hours remaining'));
    assert_false((bool) preg_match('/\bis active\b|\blicense is active\b/i', $text), 'must not say the license is active after expiry');
    assert_false((bool) preg_match('/network|connect|offline|reach/i', $text), 'grace is commercial — never attributed to connectivity');
    assert_eq('enabled', array_column($v['modules'], 'state', 'key')['forms'], 'entitled modules stay enabled during grace');
    assert_eq('not_included', array_column($v['modules'], 'state', 'key')['membership']);
});

unit('Phase 9 presenter: the last second of grace shows "less than a minute", never a rounded-up figure', function () {
    $v = clw_view(7 * CLW_DAY - 1);
    assert_eq('grace', $v['state']);
    assert_eq('less than a minute', $v['time_remaining']);
});

unit('Phase 9 presenter: past grace is locked as Expired, with expiry and grace-end dates, no banner and no enabled module', function () {
    $v = clw_view(7 * CLW_DAY, [], null);
    assert_eq('expired', $v['state']);
    assert_true($v['locked']);
    assert_null($v['banner']);
    assert_null($v['time_remaining']);
    assert_eq('Oct 1, 2026 12:00 UTC', $v['expires_label']);
    assert_eq('Oct 8, 2026 12:00 UTC', $v['grace_ends_label']);
});

unit('Phase 9 presenter: suspended / revoked inside nominal expiry show their own lock, never a grace banner', function () {
    foreach (['suspended', 'revoked'] as $status) {
        $v = clw_view(CLW_DAY, ['status' => $status]);
        assert_eq($status, $v['state']);
        assert_true($v['locked']);
        assert_null($v['banner']);
        assert_null($v['grace_ends_label'], 'no grace detail for an administrative lock');
    }
});

unit('Phase 9 presenter: a malformed trusted row is presented as Unverified with no details', function () {
    $v = clw_view(-30 * CLW_DAY, ['expires_at' => 'garbage']);
    assert_eq('untrusted', $v['state']);
    assert_false($v['show_details']);
    assert_null($v['banner']);
});

unit('Phase 9 presenter: a Guard that is locked always wins over the timeline (no banner, locked view)', function () {
    $v = clw_view(-3 * CLW_DAY, [], ['locked' => true, 'reason' => 'stale']);
    assert_true($v['locked']);
    assert_eq('stale', $v['state']);
    assert_null($v['banner']);
});
