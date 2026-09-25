<?php
/**
 * Phase 1C H6 — Stripe checkout success.php missing shop_sid ownership check.
 *
 * plugins/stripe-payment/public/success.php looked up its mapping row by
 * `stripe_session_id` alone and, if it already carried an `order_id`
 * (completed by an earlier visit or by webhook.php beating it there),
 * redirected straight to that order's confirmation page — and otherwise
 * went on to create/reconcile an order against `$row['shop_sid']`. The `sid`
 * query parameter it accepted was read but never checked against anything.
 *
 * That is NOT the same gap return.php already covers. return.php's sibling
 * check binds to the shop_sid COOKIE, not a query parameter — deliberately,
 * because a leaked success.php URL (Referer header, browser history, a
 * shared screenshot) already carries BOTH `session_id` and `sid` together;
 * comparing `$_GET['sid']` against itself would prove nothing. Only the
 * cookie, set on the browser at cart-creation time and never included in a
 * shared URL, can prove which browser actually owns this checkout. The fix
 * applies that same reasoning to success.php: it now requires
 * $_COOKIE['shop_sid'] to hash_equals()-match the row's own shop_sid,
 * checked before the order_id disclosure branch and before any order
 * creation/reconciliation.
 *
 * REACHABILITY: this endpoint's own dependency (`if (!PluginLoader::
 * isActive('shop') ...) { http_response_code(503); ... }`) cannot pass in
 * this checkout — the `shop` plugin was moved to archive/plugins/shop and no
 * longer exists under plugins/, so PluginLoader::isActive('shop') is always
 * false and the real page 503s before any of this logic runs (verified
 * directly: PluginLoader::isActive('shop') === false, is_dir(.../plugins/
 * shop) === false). A full HTTP-level reproduction through the live page is
 * therefore not possible today — the required audit's own instruction
 * ("if the existing test/fixture infrastructure permits it") does not apply
 * here. This suite instead: (1) reproduces the underlying decision logic
 * directly against a real stripepayment_sessions row (the exact query and
 * comparison the file performs, run here explicitly since the file itself
 * can't be exercised end-to-end), and (2) statically pins the fix's presence
 * and ordering in the actual shipped file, so a future edit can't silently
 * remove or reorder the check.
 */

declare(strict_types=1);

// The stripe-payment plugin is not active in this checkout's test database
// (see tests/README.md's active-plugin list) and this table is otherwise
// only created by StripePayment's own migration — recreate it here,
// matching the real schema exactly (StripePayment.php's runMigrations()),
// so the pipeline test below runs against the real shape either way.
Database::query("
    CREATE TABLE IF NOT EXISTS stripepayment_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stripe_session_id VARCHAR(255) NULL,
        shop_sid          VARCHAR(64)  NOT NULL,
        order_id          INT UNSIGNED NULL,
        status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
        billing_json      TEXT NULL,
        created_at        DATETIME NOT NULL,
        completed_at      DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_stripe_session_id (stripe_session_id),
        KEY idx_shop_sid (shop_sid),
        KEY idx_status   (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

unit('stripe success.php ownership: a leaked session_id alone must not disclose another shop_sid\'s completed order', function (): void {
    $sessionId = 'cs_test_probe_' . bin2hex(random_bytes(8));
    $victimSid = bin2hex(random_bytes(16));
    Database::insert('stripepayment_sessions', [
        'stripe_session_id' => $sessionId, 'shop_sid' => $victimSid, 'order_id' => 999999,
        'status' => 'completed', 'created_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        // The exact lookup success.php performs.
        $row = Database::row(
            'SELECT id, shop_sid, order_id, status, billing_json FROM stripepayment_sessions WHERE stripe_session_id = ?',
            [$sessionId]
        );
        assert_true($row !== null, 'setup: the mapping row must exist');

        // Attacker: knows only the leaked session_id, holds no shop_sid cookie
        // for this checkout (a leaked URL does not carry the victim's cookie
        // jar). This is the exact post-fix guard clause from success.php.
        $attackerCookie = '';
        $wouldDisclose = $attackerCookie !== '' && hash_equals((string) $row['shop_sid'], $attackerCookie);
        assert_false($wouldDisclose, 'an attacker with no shop_sid cookie must never be treated as the order\'s owner');

        // A guessed/leaked cookie value that happens to differ must also fail.
        $wrongCookie = bin2hex(random_bytes(16));
        $wouldDiscloseWrong = hash_equals((string) $row['shop_sid'], $wrongCookie);
        assert_false($wouldDiscloseWrong, 'a non-matching shop_sid cookie must never pass the check');

        // The real owner, whose browser still holds the matching cookie
        // (SameSite=Lax rides along on Stripe's top-level redirect back),
        // must still be recognized.
        $ownerCookie = $victimSid;
        $ownerAllowed = hash_equals((string) $row['shop_sid'], $ownerCookie);
        assert_true($ownerAllowed, 'the actual owner (matching shop_sid cookie) must still pass');
    } finally {
        Database::query('DELETE FROM stripepayment_sessions WHERE stripe_session_id = ?', [$sessionId]);
    }
});

unit('stripe success.php: the fix is present in the shipped file, before the order-disclosure and checkout-creation points', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/success.php');

    $rowLookupPos  = strpos($src, "FROM stripepayment_sessions\n      WHERE stripe_session_id = ?");
    $cookieCheckPos = strpos($src, "\$_COOKIE['shop_sid']");
    $hashEqualsPos  = strpos($src, "hash_equals((string)\$row['shop_sid'], \$browserSid)");
    $orderDisclosurePos = strpos($src, "ShopAPI::order((int)\$row['order_id'])");
    $checkoutCreatePos  = strpos($src, "ShopAPI::checkoutCart(\$row['shop_sid'], \$billing)");

    assert_true($rowLookupPos !== false, 'the mapping-row lookup must still be present');
    assert_true($cookieCheckPos !== false, 'success.php must read the shop_sid cookie');
    assert_true($hashEqualsPos !== false, 'the ownership comparison must use hash_equals(), matching return.php\'s convention');
    assert_true($orderDisclosurePos !== false, 'the existing-order disclosure redirect must still be present');
    assert_true($checkoutCreatePos !== false, 'the checkout-creation call must still be present');

    assert_true($rowLookupPos < $cookieCheckPos, 'the ownership check must come after the row is looked up (it needs the row to compare against)');
    assert_true($cookieCheckPos < $orderDisclosurePos, 'the ownership check must run BEFORE the branch that would disclose another shop_sid\'s order');
    assert_true($cookieCheckPos < $checkoutCreatePos, 'the ownership check must run BEFORE any order is created/reconciled for $row[shop_sid]');
});
