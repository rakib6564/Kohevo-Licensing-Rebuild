<?php
/**
 * Drives, against a real stripepayment_sessions row, the check-then-act
 * shapes relevant to Phase 1D C3 (success.php's TOCTOU race) — a dedicated,
 * self-contained fixture (not shared with C1's stripe-claim-probe.php), per
 * the standing instruction to keep each Stripe TOCTOU finding independently
 * assessed rather than folded together.
 *
 * Modes:
 *   old     — success.php's PRE-FIX pattern: SELECT order_id, check empty,
 *             do (simulated) checkoutCart()+Stripe-verify work, then an
 *             UNCONDITIONAL write. A frozen copy of that historical shape,
 *             kept to demonstrate the vulnerability concretely.
 *   claim   — a bare atomic claimer with NO retry-on-rejection: the exact
 *             `UPDATE ... WHERE order_id IS NULL AND status != 'claimed'`
 *             statement shape (byte-identical to both webhook.php's
 *             completePayment() and success.php's own claim). Used to play
 *             the role of "some other concurrent claimant" — e.g.
 *             webhook.php — in a cross-path race, without that side's own
 *             retry/observe behavior.
 *   success — the actual NEW success.php pattern under test: attempt the
 *             atomic claim; if it wins, do (simulated) checkoutCart() work
 *             then either the success write or (if failAfterClaim) the
 *             release back to 'pending'; if it loses, run success.php's own
 *             successWaitForCompletion() shape (5 x 100ms re-reads) and
 *             report whether it observed the winner's order_id land, or
 *             timed out and failed safe.
 *   finisher — unconditionally sleeps a given delay, then writes
 *             order_id/status=completed regardless of current state.
 *             Simulates "the other path finishing its already-in-flight
 *             work" at a controlled, deterministic time, to test
 *             success.php's wait-and-observe behavior without depending on
 *             winning an actual claim race.
 *
 * The 150ms sleep (old/claim/success work) stands in for
 * ShopAPI::checkoutCart()'s + StripeAPI::getSession()'s real work — long
 * enough to reliably force concurrently-launched OS processes to overlap.
 *
 * Usage:
 *   php success-claim-probe.php old|claim|success <rowId> <fakeOrderId> [failAfterClaim=0]
 *   php success-claim-probe.php finisher <rowId> <fakeOrderId> <delayMs>
 *
 * Prints one line:
 *   old/claim:    PROCEEDED | SKIPPED | RELEASED
 *   success:      PROCEEDED:<orderId> | WINNER_OBSERVED:<orderId> | FAILED_SAFE | RELEASED
 *   finisher:     FINISHED:<orderId>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 2) . '/config.php';

$mode           = $argv[1] ?? '';
$rowId          = (int)($argv[2] ?? 0);
$fakeOrderId    = (int)($argv[3] ?? 0);
$fourth         = $argv[4] ?? '0';

if (!in_array($mode, ['old', 'claim', 'success', 'finisher'], true) || $rowId <= 0) {
    fwrite(STDERR, "usage: success-claim-probe.php old|claim|success|finisher <rowId> <fakeOrderId> [failAfterClaim|delayMs]\n");
    exit(2);
}

if ($mode === 'finisher') {
    $delayMs = (int)$fourth;
    usleep(max(0, $delayMs) * 1000);
    Database::update('stripepayment_sessions', [
        'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
    ], 'id = ?', [$rowId]);
    echo "FINISHED:$fakeOrderId\n";
    exit;
}

$failAfterClaim = $fourth === '1';

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

if ($mode === 'claim') {
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
    usleep(150000);
    if ($failAfterClaim) {
        Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
        echo "RELEASED\n";
        exit;
    }
    Database::update('stripepayment_sessions', [
        'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
    ], 'id = ?', [$rowId]);
    echo "PROCEEDED\n";
    exit;
}

// 'success' — the exact shape shipped in plugins/stripe-payment/public/success.php.
$claimed = Database::update(
    'stripepayment_sessions',
    ['status' => 'claimed'],
    'id = ? AND order_id IS NULL AND status != ?',
    [$rowId, 'claimed']
);

if ($claimed === 0) {
    // successWaitForCompletion()'s exact shape: 5 x 100ms re-reads.
    for ($i = 0; $i < 5; $i++) {
        usleep(100000);
        $fresh = Database::row("SELECT order_id FROM stripepayment_sessions WHERE id = ?", [$rowId]);
        if ($fresh && !empty($fresh['order_id'])) {
            echo 'WINNER_OBSERVED:' . $fresh['order_id'] . "\n";
            exit;
        }
    }
    echo "FAILED_SAFE\n";
    exit;
}

usleep(150000); // simulate StripeAPI::getSession() + ShopAPI::checkoutCart()'s real work window

if ($failAfterClaim) {
    Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
    echo "RELEASED\n";
    exit;
}

Database::update('stripepayment_sessions', [
    'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
], 'id = ?', [$rowId]);
echo "PROCEEDED:$fakeOrderId\n";
