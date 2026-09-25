<?php
/**
 * Phase 1D C2 — Stripe permission granularity.
 *
 * plugins/stripe-payment/admin/charges.php (view the charge ledger + issue
 * real, irreversible refunds via the live Stripe API) and admin/settings.php
 * (view/edit live+test API keys and the webhook secret) were both gated by
 * the SAME single permission, stripe.manage_settings. Any role granted one
 * capability automatically got the other — there was no way to delegate
 * refund-handling to a support role without also handing it the ability to
 * rotate Stripe credentials, or vice versa.
 *
 * Fix splits this into two ordinary, tenant-delegable permissions —
 * stripe.manage_settings (credentials/config only) and the new
 * stripe.manage_charges (view + refund) — mirroring the Booking plugin's
 * existing manage_settings/manage_payments split exactly. No new RBAC
 * mechanism, no protected-permission list, no platform-admin elevation:
 * Stripe charges/refunds stay tenant-owned and tenant-delegable, per the
 * approved design.
 *
 * Deliberately no back-compat migration: a role that held only
 * stripe.manage_settings before this fix loses refund capability until
 * stripe.manage_charges is explicitly granted. Auto-granting both new
 * permissions to every existing holder of the old one would just
 * recreate the exact bundle this fix exists to break.
 *
 * Every test here drives the real POST handlers directly via
 * admin-page-post-probe.php — this proves the actual server-side
 * authorization boundary, not just nav-item visibility, and (since the
 * probe never touches the nav-rendering layer at all) proves a direct POST
 * cannot bypass the permission through it.
 */

declare(strict_types=1);

use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePayment.php';

/**
 * Sign in, in-process, as the given role id — same technique as
 * BookingSettingsSeamTest.php's bss_login(). Needed to exercise
 * addAdminNav()/addDashboardWidget() live: Auth::can() requires a session
 * validated against a real admin_sessions row (Auth::check()), so setting
 * $_SESSION alone is not enough. Returns the exact undo.
 */
function spst_login_as(int $tid, int $roleId): callable
{
    $admin = Database::row("SELECT id FROM users WHERE tenant_id = ? AND status = 'active' LIMIT 1", [$tid]);
    if ($admin === null) throw new RuntimeException('no active admin in the test fixture');
    $userId = (int) $admin['id'];

    Auth::startSession();
    $prior = $_SESSION['slate_user'] ?? null;
    $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $tid, 'email' => 'spst@example.test', 'role_id' => $roleId];
    Auth::invalidatePermCache();

    $sid = (new SessionRepository(new TenantContext()))->register($userId, session_id(), 'SPST test', '127.0.0.1', 'SlateTest/1');

    return static function () use ($prior, $sid, $tid): void {
        Database::query('DELETE FROM admin_sessions WHERE id = ? AND tenant_id = ?', [$sid, $tid]);
        if ($prior !== null) { $_SESSION['slate_user'] = $prior; } else { unset($_SESSION['slate_user']); }
        Auth::invalidatePermCache();
    };
}

function spst_post(string $page, array $fields, int $roleId, bool $platformAdmin = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg($page) . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' ' . escapeshellarg($platformAdmin ? '1' : '0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . $out);
    }
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

/** A throwaway tenant-scoped role granting the given permission keys. */
function spst_make_role(int $tid, array $permKeys): int
{
    $roleId = Database::insert('roles', [
        'tenant_id' => $tid, 'name' => 'Probe SPST', 'slug' => '__probe-spst-' . bin2hex(random_bytes(4)), 'is_system' => 0,
    ]);
    foreach ($permKeys as $perm) {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
    }
    return $roleId;
}

function spst_drop_role(int $roleId): void
{
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

/** Safe, non-network POST to settings.php — clear_keys never calls Stripe. */
const SPST_SETTINGS_FIELDS = ['_action' => 'clear_keys'];
/** Safe, non-network POST to charges.php — a nonexistent charge id short-
 *  circuits inside refundCharge() ("Charge not found") before any Stripe
 *  API call is made; what's under test is whether the request is
 *  authorized to REACH that code at all, not whether a refund succeeds. */
const SPST_CHARGES_FIELDS = ['_action' => 'refund', 'id' => '999999999'];

$SPST_TID = current_tenant_id();

unit('C2: a settings-only role can access settings but is denied charges/refunds', function () use ($SPST_TID): void {
    $roleId = spst_make_role($SPST_TID, ['stripe.manage_settings']);
    try {
        [$settingsStatus, $settingsBody] = spst_post('plugins/stripe-payment/admin/settings.php', SPST_SETTINGS_FIELDS, $roleId);
        assert_eq(200, $settingsStatus, "settings-only role must reach settings.php: $settingsBody");
        assert_false(str_contains($settingsBody, '403 Forbidden'), 'settings-only role must not be refused on settings.php');

        [$chargesStatus, $chargesBody] = spst_post('plugins/stripe-payment/admin/charges.php', SPST_CHARGES_FIELDS, $roleId);
        assert_eq(403, $chargesStatus, "a settings-only role must be refused on charges.php — this is the exact bundling C2 closes: $chargesBody");
        assert_true(str_contains($chargesBody, 'stripe.manage_charges'), 'the refusal must name the specific missing permission');
    } finally {
        spst_drop_role($roleId);
    }
});

unit('C2: a charges-only role can access charges/refunds but is denied settings', function () use ($SPST_TID): void {
    $roleId = spst_make_role($SPST_TID, ['stripe.manage_charges']);
    try {
        [$chargesStatus, $chargesBody] = spst_post('plugins/stripe-payment/admin/charges.php', SPST_CHARGES_FIELDS, $roleId);
        assert_eq(200, $chargesStatus, "charges-only role must reach charges.php: $chargesBody");
        assert_false(str_contains($chargesBody, '403 Forbidden'), 'charges-only role must not be refused on charges.php');
        assert_true(str_contains($chargesBody, 'Charge not found'), 'the request must reach the actual refund logic (proving authorization, not just page load)');

        [$settingsStatus, $settingsBody] = spst_post('plugins/stripe-payment/admin/settings.php', SPST_SETTINGS_FIELDS, $roleId);
        assert_eq(403, $settingsStatus, "a charges-only role must be refused on settings.php: $settingsBody");
        assert_true(str_contains($settingsBody, 'stripe.manage_settings'), 'the refusal must name the specific missing permission');
    } finally {
        spst_drop_role($roleId);
    }
});

unit('C2: a role with BOTH permissions can access both settings and charges/refunds', function () use ($SPST_TID): void {
    $roleId = spst_make_role($SPST_TID, ['stripe.manage_settings', 'stripe.manage_charges']);
    try {
        [$settingsStatus, $settingsBody] = spst_post('plugins/stripe-payment/admin/settings.php', SPST_SETTINGS_FIELDS, $roleId);
        assert_eq(200, $settingsStatus);
        assert_false(str_contains($settingsBody, '403 Forbidden'), 'a role holding both permissions must reach settings.php');

        [$chargesStatus, $chargesBody] = spst_post('plugins/stripe-payment/admin/charges.php', SPST_CHARGES_FIELDS, $roleId);
        assert_eq(200, $chargesStatus);
        assert_false(str_contains($chargesBody, '403 Forbidden'), 'a role holding both permissions must reach charges.php');
    } finally {
        spst_drop_role($roleId);
    }
});

unit('C2: a role with NEITHER permission is denied both settings and charges/refunds', function () use ($SPST_TID): void {
    $roleId = spst_make_role($SPST_TID, []);
    try {
        [$settingsStatus, $settingsBody] = spst_post('plugins/stripe-payment/admin/settings.php', SPST_SETTINGS_FIELDS, $roleId);
        assert_eq(403, $settingsStatus, "a role with neither permission must be refused on settings.php: $settingsBody");

        [$chargesStatus, $chargesBody] = spst_post('plugins/stripe-payment/admin/charges.php', SPST_CHARGES_FIELDS, $roleId);
        assert_eq(403, $chargesStatus, "a role with neither permission must be refused on charges.php: $chargesBody");
    } finally {
        spst_drop_role($roleId);
    }
});

unit('C2: super-admin behavior is unaffected — reaches both pages regardless of explicit grants', function (): void {
    // role_id => 1 is Super Admin (Auth::isSuperAdmin()), which Auth::can()
    // short-circuits to true for any key — no role_permissions row needed,
    // matching every other capability test in this suite (e.g. H4's).
    [$settingsStatus, $settingsBody] = spst_post('plugins/stripe-payment/admin/settings.php', SPST_SETTINGS_FIELDS, 1);
    assert_eq(200, $settingsStatus);
    assert_false(str_contains($settingsBody, '403 Forbidden'), 'a super admin must reach settings.php unconditionally');

    [$chargesStatus, $chargesBody] = spst_post('plugins/stripe-payment/admin/charges.php', SPST_CHARGES_FIELDS, 1);
    assert_eq(200, $chargesStatus);
    assert_false(str_contains($chargesBody, '403 Forbidden'), 'a super admin must reach charges.php unconditionally');
});

unit('C2: nav metadata gates each item on its own new permission, and a single-permission role only ever sees its one item', function () use ($SPST_TID): void {
    $plugin = new StripePayment('stripe-payment', ['version' => '1.2.1'], dirname(__DIR__, 2) . '/plugins/stripe-payment');

    $findBySlug = static function (array $items, string $slug): ?array {
        foreach ($items as $item) {
            if (($item['slug'] ?? '') === $slug) return $item;
        }
        return null;
    };

    // Structural fact: each item's own 'perm' metadata (actively enforced
    // by admin/partials/header.php's per-item Auth::can() filter, not
    // decorative) must name its own new permission.
    $settingsOnlyRole = spst_make_role($SPST_TID, ['stripe.manage_settings']);
    $logout = spst_login_as($SPST_TID, $settingsOnlyRole);
    try {
        $items = $plugin->addAdminNav([]);
        $settingsItem = $findBySlug($items, 'shop-stripe');
        $chargesItem  = $findBySlug($items, 'shop-stripe-charges');
        assert_true($settingsItem !== null, 'a settings-only role must still see the Settings item (proves the OR gate lets it through)');
        assert_eq('stripe.manage_settings', $settingsItem['perm'] ?? null, 'the Settings nav item must gate on stripe.manage_settings');
        assert_true($chargesItem !== null, 'the Charges item is always registered by addAdminNav() — visibility is enforced by header.php\'s per-item filter, not by omission here');
        assert_eq('stripe.manage_charges', $chargesItem['perm'] ?? null, 'the Charges nav item must gate on the new stripe.manage_charges, not the settings permission');
    } finally {
        $logout();
        spst_drop_role($settingsOnlyRole);
    }

    // A charges-only role must still pass the top-level OR gate (must NOT
    // be blocked from ever seeing its own Charges item just because it
    // lacks stripe.manage_settings).
    $chargesOnlyRole = spst_make_role($SPST_TID, ['stripe.manage_charges']);
    $logout = spst_login_as($SPST_TID, $chargesOnlyRole);
    try {
        $items = $plugin->addAdminNav([]);
        assert_true($findBySlug($items, 'shop-stripe-charges') !== null, 'a charges-only role must not be blocked by the top-level gate from seeing the Charges item');
    } finally {
        $logout();
        spst_drop_role($chargesOnlyRole);
    }

    // Neither permission: the top-level gate must block the whole block.
    $neitherRole = spst_make_role($SPST_TID, []);
    $logout = spst_login_as($SPST_TID, $neitherRole);
    try {
        assert_eq([], $plugin->addAdminNav([]), 'a role with neither permission must see no Stripe nav items at all');
    } finally {
        $logout();
        spst_drop_role($neitherRole);
    }

    // The plugin.json manifest — what the role-editor checkbox grid and
    // Auth::knownPermissions() actually read — must declare both keys.
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/plugin.json'), true);
    $permKeys = array_column($manifest['permissions'] ?? [], 'key');
    assert_true(in_array('stripe.manage_settings', $permKeys, true), 'the manifest must still declare stripe.manage_settings');
    assert_true(in_array('stripe.manage_charges', $permKeys, true), 'the manifest must declare the new stripe.manage_charges');
});

unit('C2: the dashboard widget gates on stripe.manage_charges, matching its charge-activity content', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePayment.php');
    $fnPos = strpos($src, 'function addDashboardWidget(array $widgets): array {');
    assert_true($fnPos !== false, 'addDashboardWidget() must still exist');
    $gateLine = substr($src, $fnPos, strpos($src, "\n", $fnPos + 1) + 120 - $fnPos);

    assert_true(
        str_contains($gateLine, "Auth::can('stripe.manage_charges')"),
        'the dashboard widget (recent CHARGE activity) must gate on stripe.manage_charges, not stripe.manage_settings'
    );
});
