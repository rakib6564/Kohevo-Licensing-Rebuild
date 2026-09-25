<?php
/**
 * Drives, against a real stripepayment_sessions row, the two check-then-act
 * shapes relevant to Phase 1D C1 (webhook.php's completePayment() TOCTOU):
 *
 *   old   — the PRE-FIX pattern completePayment() used before this fix:
 *           SELECT order_id, check empty, do (simulated) checkoutCart()
 *           work, unconditionally UPDATE order_id. A literal, frozen copy
 *           of that historical shape — diffable against git history on
 *           plugins/stripe-payment/public/webhook.php — kept here to
 *           demonstrate the vulnerability concretely. The real function
 *           can't be executed end-to-end in this deployment: ShopAPI/the
 *           shop plugin is archived, not present under plugins/, and
 *           webhook.php itself refuses to reach completePayment() at all
 *           when shop isn't active.
 *   claim — the C1-FIXED pattern now shipped in completePayment(): the
 *           exact atomic `UPDATE ... WHERE order_id IS NULL AND status !=
 *           'claimed'` claim, then (simulated) checkoutCart() work, then
 *           either the success write or (if failAfterClaim) the release
 *           back to 'pending' — mirroring completePayment()'s own
 *           try/catch release path.
 *
 * The 150ms sleep stands in for ShopAPI::checkoutCart()'s real work
 * (multiple inserts, stock decrements) — long enough to reliably force two
 * concurrently-launched OS processes to overlap inside the race window.
 *
 * Usage: php stripe-claim-probe.php old|claim <rowId> <fakeOrderId> [failAfterClaim=0]
 * Prints one line: PROCEEDED, SKIPPED, or RELEASED (claim mode with
 * failAfterClaim=1 only).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 2) . '/config.php';

$mode           = $argv[1] ?? '';
$rowId          = (int)($argv[2] ?? 0);
$fakeOrderId    = (int)($argv[3] ?? 0);
$failAfterClaim = ($argv[4] ?? '0') === '1';

if (!in_array($mode, ['old', 'claim'], true) || $rowId <= 0) {
    fwrite(STDERR, "usage: stripe-claim-probe.php old|claim <rowId> <fakeOrderId> [failAfterClaim]\n");
    exit(2);
}

if ($mode === 'old') {
    $row = Database::row("SELECT order_id FROM stripepayment_sessions WHERE id = ?", [$rowId]);
    if (!empty($row['order_id'])) {
        echo "SKIPPED\n";
        exit;
    }
    usleep(150000);
    Database::update('stripepayment_sessions', [
        'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
    ], 'id = ?', [$rowId]);
    echo "PROCEEDED\n";
    exit;
}

// 'claim' — the exact statement shape shipped in completePayment().
$claimed = Database::update(
    'stripepayment_sessions',
    ['status' => 'claimed'],
    'id = ? AND order_id IS NULL AND status != ?',
    [$rowId, 'claimed']
);
if ($claimed === 0) {
    echo "SKIPPED\n";
    exit;
}

usleep(150000); // simulate ShopAPI::checkoutCart()'s real work window

if ($failAfterClaim) {
    Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
    echo "RELEASED\n";
    exit;
}

Database::update('stripepayment_sessions', [
    'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
], 'id = ?', [$rowId]);
echo "PROCEEDED\n";
