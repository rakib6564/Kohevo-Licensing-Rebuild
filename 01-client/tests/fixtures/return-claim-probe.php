<?php
/**
 * Drives, against a real stripepayment_sessions row, the check-then-act
 * shapes relevant to Phase 1D C4 (return.php's TOCTOU race) — a dedicated,
 * self-contained fixture (not shared with C1's stripe-claim-probe.php or
 * C3's success-claim-probe.php), per the standing instruction to keep each
 * Stripe TOCTOU finding independently assessed rather than folded together.
 *
 * Modes:
 *   old      — return.php's PRE-FIX pattern: SELECT order_id, check empty,
 *              do (simulated) getPaymentIntent()+checkoutCart() work, then
 *              an UNCONDITIONAL write. A frozen copy of that historical
 *              shape, kept to demonstrate the vulnerability concretely.
 *   claim    — a bare atomic claimer with NO retry-on-rejection: the exact
 *              `UPDATE ... WHERE order_id IS NULL AND status != 'claimed'`
 *              statement shape (byte-identical to webhook.php's
 *              completePayment(), success.php, and return.php's own
 *              claim). Used to play the role of "some other concurrent
 *              claimant" — e.g. webhook.php — without that side's own
 *              retry/observe behavior.
 *   return   — the actual NEW return.php pattern under test: attempt the
 *              atomic claim; if it wins, do (simulated) checkoutCart() work
 *              then either the success write or (if failAfterClaim) the
 *              release back to 'pending'; if it loses, run return.php's
 *              own returnWaitForCompletion() shape (5 x 100ms re-reads)
 *              and report whether it observed the winner's order_id land,
 *              or timed out and failed safe.
 *   embedded — StripePayment::handleEmbeddedCheckout()'s ACTUAL, UNCHANGED
 *              shape: SELECT order_id (never status), check empty, do
 *              (simulated) getPaymentIntent()+checkoutCart() work, then an
 *              UNCONDITIONAL write. This mode exists to demonstrate the
 *              KNOWN RESIDUAL GAP — that return.php's fix cannot protect
 *              against this path — WITHOUT modifying handleEmbeddedCheckout()
 *              itself, which is out of scope for C4.
 *   finisher — unconditionally sleeps a given delay, then writes
 *              order_id/status=completed regardless of current state.
 *              Simulates "the other path finishing its already-in-flight
 *              work" at a controlled, deterministic time.
 *
 * The 150ms sleep (old/claim/return/embedded work) stands in for
 * StripeAPI::getPaymentIntent()'s + ShopAPI::checkoutCart()'s real work —
 * long enough to reliably force concurrently-launched OS processes to
 * overlap.
 *
 * Usage:
 *   php return-claim-probe.php old|claim|return|embedded <rowId> <fakeOrderId> [failAfterClaim=0]
 *   php return-claim-probe.php finisher <rowId> <fakeOrderId> <delayMs>
 *
 * Prints one line:
 *   old/claim/embedded: PROCEEDED | SKIPPED | RELEASED
 *   return:             PROCEEDED:<orderId> | WINNER_OBSERVED:<orderId> | FAILED_SAFE | RELEASED
 *   finisher:            FINISHED:<orderId>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 2) . '/config.php';

$mode        = $argv[1] ?? '';
$rowId       = (int)($argv[2] ?? 0);
$fakeOrderId = (int)($argv[3] ?? 0);
$fourth      = $argv[4] ?? '0';

if (!in_array($mode, ['old', 'claim', 'return', 'embedded', 'finisher'], true) || $rowId <= 0) {
    fwrite(STDERR, "usage: return-claim-probe.php old|claim|return|embedded|finisher <rowId> <fakeOrderId> [failAfterClaim|delayMs]\n");
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

if ($mode === 'old' || $mode === 'embedded') {
    // embedded mode is byte-for-byte the same check-then-act shape as old —
    // that IS the point: handleEmbeddedCheckout() never reads status, so it
    // behaves identically to return.php's own pre-fix pattern, and remains
    // unaffected by return.php's fix.
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

// 'return' — the exact shape shipped in plugins/stripe-payment/public/return.php.
$claimed = Database::update(
    'stripepayment_sessions',
    ['status' => 'claimed'],
    'id = ? AND order_id IS NULL AND status != ?',
    [$rowId, 'claimed']
);

if ($claimed === 0) {
    // returnWaitForCompletion()'s exact shape: 5 x 100ms re-reads.
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

usleep(150000); // simulate StripeAPI::getPaymentIntent() + ShopAPI::checkoutCart()'s real work window

if ($failAfterClaim) {
    Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
    echo "RELEASED\n";
    exit;
}

Database::update('stripepayment_sessions', [
    'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
], 'id = ?', [$rowId]);
echo "PROCEEDED:$fakeOrderId\n";
