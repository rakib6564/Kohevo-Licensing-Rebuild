<?php
/**
 * Phase 8: LicenseStatusPresenter::fromInputs() — the pure mapping behind
 * the client License page and dashboard summary. Inputs are exactly the
 * shapes the real trusted sources return (SlateLicenseCacheStore::
 * readTrustState(), slate_license_guard_state(), ModuleGuard::isEntitled()
 * + EntitlementService::canAccessCapability()), so every state in Phase 8
 * §17 is covered here without a database. End-to-end rendering of the same
 * states through the real page lives in
 * tests/integration/ClientLicenseUiTest.php.
 */

declare(strict_types=1);

use Slate\Services\Licensing\LicenseStatusPresenter;

function lsp_trusted(array $data): array {
    return ['found' => true, 'trusted' => true, 'data' => $data + [
        'status' => 'active', 'plan' => 'Professional', 'entitlements' => [],
        'expires_at' => null, 'fetched_at' => date('Y-m-d H:i:s'),
        'installation_id' => str_repeat('a', 32),
    ]];
}

/** Module answers keyed by module: [entitled, licensed]. Unlisted = neither. */
function lsp_modules(array $answers = []): callable {
    return static function (string $key) use ($answers): array {
        [$entitled, $licensed] = $answers[$key] ?? [false, false];
        return ['entitled' => $entitled, 'licensed' => $licensed];
    };
}

function lsp_module_states(array $view): array {
    return array_column($view['modules'], 'state', 'key');
}

const LSP_UNLOCKED = ['locked' => false, 'reason' => null];

unit('Phase 8 presenter: active license exposes status, plan, expiry and enabled modules', function () {
    $expires = date('Y-m-d H:i:s', time() + 30 * 86400);
    $view = LicenseStatusPresenter::fromInputs(
        lsp_trusted(['expires_at' => $expires]),
        LSP_UNLOCKED,
        lsp_modules(['forms' => [true, true], 'booking' => [true, true]])
    );
    assert_eq('active', $view['state']);
    assert_eq('Active', $view['label']);
    assert_eq('success', $view['tone']);
    assert_false($view['locked']);
    assert_true($view['show_details']);
    assert_eq('Professional', $view['plan']);
    assert_eq($expires, $view['expires_at']);
    assert_eq(['forms' => 'enabled', 'membership' => 'not_included', 'booking' => 'enabled'], lsp_module_states($view));
});

unit('Phase 8 presenter: trial is presented as its own active-style state', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted(['status' => 'trial']), LSP_UNLOCKED, lsp_modules());
    assert_eq('trial', $view['state']);
    assert_eq('success', $view['tone']);
});

unit('Phase 8 presenter: a license with no expiry date shows none rather than a fabricated one', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), LSP_UNLOCKED, lsp_modules());
    assert_true($view['show_details']);
    assert_null($view['expires_at']);
});

unit('Phase 8 presenter: only Form Builder, Membership and Booking are optional modules — Core never is', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), LSP_UNLOCKED, lsp_modules());
    $keys = array_column($view['modules'], 'key');
    assert_eq(['forms', 'membership', 'booking'], $keys);
    assert_eq(['Form Builder', 'Membership', 'Booking'], array_column($view['modules'], 'label'));
    foreach (['admin-user', 'dashboard', 'site-settings', 'core', 'editor', 'content'] as $notOptional) {
        assert_false(in_array($notOptional, $keys, true), "$notOptional must not be listed as an optional module");
    }
});

unit('Phase 8 presenter: an unentitled module is never shown as enabled', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), LSP_UNLOCKED, lsp_modules(['forms' => [false, false]]));
    assert_eq('not_included', lsp_module_states($view)['forms']);
});

unit('Phase 8 presenter: a module included in the license but with its plugin switched off is not shown as enabled', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), LSP_UNLOCKED, lsp_modules(['membership' => [false, true]]));
    assert_eq('inactive', lsp_module_states($view)['membership']);
});

unit('Phase 8 presenter: past expires_at while the Guard still allows access is labelled grace, with no countdown data', function () {
    $view = LicenseStatusPresenter::fromInputs(
        lsp_trusted(['expires_at' => date('Y-m-d H:i:s', time() - 2 * 86400)]),
        LSP_UNLOCKED,
        lsp_modules()
    );
    assert_eq('grace', $view['state']);
    assert_eq('warning', $view['tone']);
    assert_false($view['locked']);
    assert_false(array_key_exists('days_remaining', $view), 'Phase 9 owns countdowns');
    foreach ($view['modules'] as $m) {
        assert_true($m['state'] !== 'not_included', 'grace must not claim a module is "not included" in the license');
    }
});

unit('Phase 8 presenter: expired beyond grace (Guard locked, reason expired) is locked and shows no enabled module', function () {
    $view = LicenseStatusPresenter::fromInputs(
        lsp_trusted(['expires_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]),
        ['locked' => true, 'reason' => 'expired'],
        lsp_modules()
    );
    assert_eq('expired', $view['state']);
    assert_true($view['locked']);
    assert_eq(['unavailable'], array_values(array_unique(lsp_module_states($view))));
});

unit('Phase 8 presenter: suspended and revoked render as locked with their own labels', function () {
    foreach (['suspended' => 'Suspended', 'revoked' => 'Revoked'] as $status => $label) {
        $view = LicenseStatusPresenter::fromInputs(
            lsp_trusted(['status' => $status]),
            ['locked' => true, 'reason' => $status],
            lsp_modules()
        );
        assert_eq($status, $view['state']);
        assert_eq($label, $view['label']);
        assert_eq('danger', $view['tone']);
        assert_true($view['locked']);
        assert_false(in_array('enabled', lsp_module_states($view), true));
    }
});

unit('Phase 8 presenter: a missing license shows "Not activated" and no plan, expiry or modules', function () {
    $view = LicenseStatusPresenter::fromInputs(
        ['found' => false, 'trusted' => false, 'data' => null],
        ['locked' => true, 'reason' => 'missing'],
        lsp_modules()
    );
    assert_eq('missing', $view['state']);
    assert_eq('Not activated', $view['label']);
    assert_false($view['show_details']);
    assert_null($view['plan']);
    assert_null($view['expires_at']);
    assert_null($view['last_verified_at']);
});

unit('Phase 8 presenter: an untrusted row (installation ID mismatch / malformed) exposes nothing', function () {
    $view = LicenseStatusPresenter::fromInputs(
        ['found' => true, 'trusted' => false, 'data' => null],
        ['locked' => true, 'reason' => 'untrusted'],
        lsp_modules()
    );
    assert_eq('untrusted', $view['state']);
    assert_null($view['plan']);
    assert_null($view['expires_at']);
    assert_null($view['last_verified_at']);
});

unit('Phase 8 presenter: a stale cache is not presented as a valid license and hides plan/expiry', function () {
    $view = LicenseStatusPresenter::fromInputs(
        lsp_trusted(['plan' => 'StalePlan', 'fetched_at' => date('Y-m-d H:i:s', time() - 30 * 86400)]),
        ['locked' => true, 'reason' => 'stale'],
        lsp_modules()
    );
    assert_eq('stale', $view['state']);
    assert_true($view['locked']);
    assert_null($view['plan']);
    assert_null($view['expires_at']);
    assert_true($view['last_verified_at'] !== null, 'when it was last verified is still useful and not a commercial claim');
});

unit('Phase 8 presenter: an unavailable/erroring trust source fails closed', function () {
    foreach (['unavailable', 'error'] as $reason) {
        $view = LicenseStatusPresenter::fromInputs(['found' => false, 'trusted' => false, 'data' => null],
            ['locked' => true, 'reason' => $reason], lsp_modules());
        assert_eq('unavailable', $view['state']);
        assert_true($view['locked']);
    }
});

unit('Phase 8 presenter: a malformed guard answer is treated as locked, never as unlocked', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), [], lsp_modules(['forms' => [true, true]]));
    assert_true($view['locked']);
    assert_eq('unavailable', $view['state']);
});

unit('Phase 8 presenter: "unlocked" without a trusted active row still shows nothing (defence in depth)', function () {
    $view = LicenseStatusPresenter::fromInputs(['found' => true, 'trusted' => false, 'data' => null], LSP_UNLOCKED, lsp_modules());
    assert_eq('untrusted', $view['state']);
    assert_false($view['show_details']);
    assert_null($view['plan']);
});

unit('Phase 8 presenter: an unrecognised commercial status is shown generically, not echoed', function () {
    $view = LicenseStatusPresenter::fromInputs(
        lsp_trusted(['status' => '<script>x</script>']),
        ['locked' => true, 'reason' => '<script>x</script>'],
        lsp_modules()
    );
    assert_eq('inactive', $view['state']);
    assert_eq('Inactive', $view['label']);
});

unit('Phase 8 presenter: malformed plan / date values are dropped, not displayed', function () {
    $view = LicenseStatusPresenter::fromInputs(
        lsp_trusted(['plan' => str_repeat('x', 500), 'expires_at' => 'not-a-date']),
        LSP_UNLOCKED,
        lsp_modules()
    );
    assert_null($view['plan']);
    assert_null($view['expires_at']);
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted(['plan' => ['array']]), LSP_UNLOCKED, lsp_modules());
    assert_null($view['plan']);
});

unit('Phase 8 presenter: output never carries the installation ID, entitlements list or any raw cache field', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted(['entitlements' => ['forms']]), LSP_UNLOCKED, lsp_modules());
    $flat = json_encode($view);
    assert_false(str_contains($flat, str_repeat('a', 32)), 'installation ID must not be part of the view model');
    foreach (['installation_id', 'entitlements', 'signature', 'license_key', 'raw_payload'] as $field) {
        assert_false(array_key_exists($field, $view), "$field must not be exposed");
    }
});

unit('Phase 8 presenter: an incompletely configured remote authority surfaces a notice instead of silently showing no modules', function () {
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), LSP_UNLOCKED, lsp_modules(), 'unconfigured');
    assert_true(is_string($view['notice']));
    $view = LicenseStatusPresenter::fromInputs(lsp_trusted([]), LSP_UNLOCKED, lsp_modules(), 'remote');
    assert_null($view['notice']);
});
