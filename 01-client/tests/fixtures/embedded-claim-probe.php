<?php
/**
 * Drives, against a real stripepayment_sessions row, the check-then-act
 * shapes relevant to Phase 1D C5 (StripePayment::handleEmbeddedCheckout()'s
 * TOCTOU race) — a dedicated, self-contained fixture (not shared with C1's
 * stripe-claim-probe.php, C3's success-claim-probe.php, or C4's
 * return-claim-probe.php), per the standing instruction to keep each Stripe
 * TOCTOU finding independently assessed rather than folded together.
 *
 * Modes:
 *   old      — handleEmbeddedCheckout()'s PRE-FIX pattern: SELECT order_id
 *              (never status), check empty, do (simulated)
 *              getPaymentIntent()+checkoutCart() work, then an
 *              UNCONDITIONAL write. A frozen copy of that historical shape,
 *              kept to demonstrate the vulnerability concretely.
 *   claim    — a bare atomic claimer with NO retry-on-rejection: the exact
 *              `UPDATE ... WHERE order_id IS NULL AND status != 'claimed'`
 *              statement shape (byte-identical to webhook.php's
 *              completePayment(), success.php, return.php, and this
 *              method's own claim). Used to play the role of "some other
 *              concurrent claimant" — e.g. webhook.php — without that
 *              side's own retry/observe behavior.
 *   embedded — the actual NEW handleEmbeddedCheckout() pattern under test:
 *              attempt the atomic claim; if it wins, do (simulated)
 *              checkoutCart() work then either the success write or (if
 *              failAfterClaim) the release back to 'pending'; if it loses,
 *              run embeddedWaitForCompletion()'s shape (5 x 100ms
 *              re-reads) and report whether it observed the winner's
 *              order_id land, or timed out and failed safe. Unlike
 *              return.php/success.php's probes, this mode's outputs mirror
 *              handleEmbeddedCheckout()'s own return-ARRAY shapes rather
 *              than a redirect — see the usage note below.
 *   return   — return.php's ACTUAL shipped (post-C4) shape, reused here
 *              only to test handleEmbeddedCheckout() against it directly
 *              (not re-testing C4 itself).
 *   finisher — unconditionally sleeps a given delay, then writes
 *              order_id/status=completed regardless of current state.
 *              Simulates "the other path finishing its already-in-flight
 *              work" at a controlled, deterministic time.
 *
 * The 150ms sleep (old/claim/embedded/return work) stands in for
 * StripeAPI::getPaymentIntent()'s + ShopAPI::checkoutCart()'s real work —
 * long enough to reliably force concurrently-launched OS processes to
 * overlap.
 *
 * Usage:
 *   php embedded-claim-probe.php old|claim|embedded|return <rowId> <fakeOrderId> [failAfterClaim=0]
 *   php embedded-claim-probe.php finisher <rowId> <fakeOrderId> <delayMs>
 *
 * Prints one line:
 *   old/claim:  PROCEEDED | SKIPPED | RELEASED
 *   embedded:   OK_ORDER:<orderId> | OK_OBSERVED:<orderId> | ERR_NO_ORDER | RELEASED
 *               (OK_ORDER/OK_OBSERVED/ERR_NO_ORDER map directly onto
 *               handleEmbeddedCheckout()'s own return array shapes:
 *               ['ok'=>true,'order_id'=>N] and
 *               ['ok'=>false,'error'=>'...'])
 *   return:     PROCEEDED:<orderId> | WINNER_OBSERVED:<orderId> | FAILED_SAFE | RELEASED
 *   finisher:   FINISHED:<orderId>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__, 2) . '/config.php';

$mode        = $argv[1] ?? '';
$rowId       = (int)($argv[2] ?? 0);
$fakeOrderId = (int)($argv[3] ?? 0);
$fourth      = $argv[4] ?? '0';

if (!in_array($mode, ['old', 'claim', 'embedded', 'return', 'finisher'], true) || $rowId <= 0) {
    fwrite(STDERR, "usage: embedded-claim-probe.php old|claim|embedded|return|finisher <rowId> <fakeOrderId> [failAfterClaim|delayMs]\n");
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
    // handleEmbeddedCheckout()'s exact pre-fix shape: reads order_id
    // (status is selected too, but never read — same defect as the other
    // three sites before their fixes).
    $row = Database::row("SELECT order_id, status FROM stripepayment_sessions WHERE id = ?", [$rowId]);
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

if ($mode === 'return') {
    // return.php's actual shipped (post-C4) shape — reused here only to
    // race handleEmbeddedCheckout() against it directly.
    $claimed = Database::update(
        'stripepayment_sessions',
        ['status' => 'claimed'],
        'id = ? AND order_id IS NULL AND status != ?',
        [$rowId, 'claimed']
    );
    if ($claimed === 0) {
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
    usleep(150000);
    if ($failAfterClaim) {
        Database::update('stripepayment_sessions', ['status' => 'pending'], 'id = ?', [$rowId]);
        echo "RELEASED\n";
        exit;
    }
    Database::update('stripepayment_sessions', [
        'order_id' => $fakeOrderId, 'status' => 'completed', 'completed_at' => slate_db_now(),
    ], 'id = ?', [$rowId]);
    echo "PROCEEDED:$fakeOrderId\n";
    exit;
}

// 'embedded' — the exact shape shipped in
// StripePayment::handleEmbeddedCheckout(), with outputs mapped onto its
// own ['ok'=>...] return array shapes.
$claimed = Database::update(
    'stripepayment_sessions',
    ['status' => 'claimed'],
    'id = ? AND order_id IS NULL AND status != ?',
    [$rowId, 'claimed']
);

if ($claimed === 0) {
    // embeddedWaitForCompletion()'s exact shape: 5 x 100ms re-reads.
    for ($i = 0; $i < 5; $i++) {
        usleep(100000);
        $fresh = Database::row("SELECT order_id FROM stripepayment_sessions WHERE id = ?", [$rowId]);
        if ($fresh && !empty($fresh['order_id'])) {
            // maps to: return ['ok' => true, 'order_id' => (int)$winner['order_id']];
            echo 'OK_OBSERVED:' . $fresh['order_id'] . "\n";
            exit;
        }
    }
    // maps to: return ['ok' => false, 'error' => '...']; — never calls checkoutCart().
    echo "ERR_NO_ORDER\n";
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
// maps to: return ['ok' => true, 'order_id' => $orderId];
echo "OK_ORDER:$fakeOrderId\n";
