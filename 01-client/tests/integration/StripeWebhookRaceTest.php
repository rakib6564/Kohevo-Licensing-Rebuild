<?php
/**
 * Phase 1D C1 — Stripe webhook TOCTOU race in completePayment()
 * (plugins/stripe-payment/public/webhook.php).
 *
 * The idempotency check (`if (!empty($row['order_id'])) { ...skip... }`)
 * read a row fetched moments earlier by an unlocked SELECT, then — only
 * AFTER ShopAPI::checkoutCart() (a real, multi-query, unguarded operation:
 * verified by reading the archived archive/plugins/shop/ShopAPI.php, which
 * has no de-duplication of its own tied to shop_sid or this mapping row)
 * — wrote order_id back. Two overlapping deliveries of the same event
 * (Stripe's own retry, or genuinely concurrent delivery) could both pass
 * the empty-check before either wrote back, both call checkoutCart(), and
 * both create a real, independent order plus a real, independent stock
 * decrement for one payment.
 *
 * Live reproduction through the real page is not possible: `shop` is
 * archived and not present under plugins/, and webhook.php's own
 * `!PluginLoader::isActive('shop')` gate refuses to reach completePayment()
 * at all in that state (same reachability caveat as H6). What IS
 * established live, with two genuinely concurrent OS processes racing the
 * real check-then-act SQL shape against a real stripepayment_sessions row,
 * is this test suite's own doing (see stripe-claim-probe.php's 'old' mode)
 * — the specific consequence (a duplicate real shop_orders row + double
 * stock decrement) is established by reading ShopAPI's archived source,
 * not executed live.
 *
 * Fix: an atomic conditional UPDATE claims the row (status: pending/
 * abandoned → 'claimed') before checkoutCart() is ever called. A single
 * UPDATE is atomic per-statement under InnoDB row locking — no explicit
 * transaction or locking read needed. A checkout failure releases the
 * claim back to 'pending' so Stripe's own retry (or a later concurrent
 * delivery) can cleanly reclaim the row.
 *
 * Scope: webhook.php only, as approved. success.php, return.php, and
 * StripePayment::handleEmbeddedCheckout() share the identical unguarded
 * pattern and are NOT touched here — tracked separately. A race between
 * webhook.php and any of those three is not closed by this fix; only
 * webhook.php's own internal race (retries / concurrent deliveries of the
 * same event) is.
 */

declare(strict_types=1);

/** Matches the real production schema (plugins/stripe-payment/StripePayment.php's
 *  runMigrations()) — this shared test table predates that migration's
 *  payment_intent_id/flow columns, needed here to test create-intent.php's
 *  real reuse-lookup query shape without inventing a different one. */
function swrt_ensure_columns(): void
{
    $has = fn(string $col): bool => (bool) Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_sessions' AND COLUMN_NAME = ?",
        [$col]
    );
    if (!$has('payment_intent_id')) {
        Database::query("ALTER TABLE stripepayment_sessions ADD COLUMN payment_intent_id VARCHAR(255) NULL AFTER stripe_session_id");
    }
    if (!$has('flow')) {
        Database::query("ALTER TABLE stripepayment_sessions ADD COLUMN flow VARCHAR(16) NOT NULL DEFAULT 'hosted' AFTER shop_sid");
    }
}
swrt_ensure_columns();

function swrt_seed(string $status = 'pending', ?int $orderId = null): int
{
    return Database::insert('stripepayment_sessions', [
        'stripe_session_id' => 'cs_test_' . bin2hex(random_bytes(8)),
        'shop_sid'          => bin2hex(random_bytes(16)),
        'order_id'          => $orderId,
        'status'            => $status,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
}

function swrt_probe_async(string $mode, int $rowId, int $fakeOrderId, bool $failAfterClaim = false): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/stripe-claim-probe.php') . ' '
         . escapeshellarg($mode) . ' ' . escapeshellarg((string) $rowId) . ' ' . escapeshellarg((string) $fakeOrderId)
         . ' ' . escapeshellarg($failAfterClaim ? '1' : '0');
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

function swrt_probe_wait(array $handle): string
{
    $out = stream_get_contents($handle['stdout']);
    fclose($handle['stdout']);
    fclose($handle['stderr']);
    proc_close($handle['proc']);
    return trim((string) $out);
}

/** Launch N probes as truly concurrent OS processes, return their outputs in launch order. */
function swrt_race(string $mode, int $rowId, array $fakeOrderIds): array
{
    $handles = [];
    foreach ($fakeOrderIds as $orderId) {
        $handles[] = swrt_probe_async($mode, $rowId, $orderId);
    }
    return array_map('swrt_probe_wait', $handles);
}

function swrt_clean(int $rowId): void
{
    Database::query('DELETE FROM stripepayment_sessions WHERE id = ?', [$rowId]);
}

unit('C1 vulnerable baseline: two concurrent completions of the PRE-FIX pattern both proceed (duplicate order creation)', function (): void {
    $rowId = swrt_seed('pending');
    try {
        $results = swrt_race('old', $rowId, [9001, 9002]);
        sort($results);
        assert_eq(['PROCEEDED', 'PROCEEDED'], $results, 'the old, unguarded pattern must let BOTH concurrent callers pass the empty-check and proceed — this is exactly the defect C1 closes');
    } finally {
        swrt_clean($rowId);
    }
});

unit('C1 fix: two concurrent completions result in exactly one claimant', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $rowId = swrt_seed('pending');
        try {
            $results = swrt_race('claim', $rowId, [9101, 9102]);
            sort($results);
            assert_eq(['PROCEEDED', 'SKIPPED'], $results, "iteration $i: exactly one of two concurrent claimants must proceed, the other must be skipped");

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_true(in_array((int) $row['order_id'], [9101, 9102], true), 'the winning claimant\'s order_id must be the one recorded');
            assert_eq('completed', $row['status'], 'the row must end up completed, not stuck at claimed');
        } finally {
            swrt_clean($rowId);
        }
    }
});

unit('C1 fix: the claim cannot be bypassed under higher concurrency (5 simultaneous claimants, exactly one wins)', function (): void {
    $rowId = swrt_seed('pending');
    try {
        $results = swrt_race('claim', $rowId, [9201, 9202, 9203, 9204, 9205]);
        $proceeded = array_filter($results, fn($r) => $r === 'PROCEEDED');
        $skipped   = array_filter($results, fn($r) => $r === 'SKIPPED');
        assert_eq(1, count($proceeded), 'exactly one of five simultaneous claimants must proceed: ' . implode(',', $results));
        assert_eq(4, count($skipped), 'the other four must all be skipped: ' . implode(',', $results));
    } finally {
        swrt_clean($rowId);
    }
});

unit('C1 fix: the losing request never reaches the (simulated) checkout/order-creation work', function (): void {
    $rowId = swrt_seed('pending');
    try {
        $start = microtime(true);
        $results = swrt_race('claim', $rowId, [9301, 9302]);
        $elapsed = microtime(true) - $start;

        sort($results);
        assert_eq(['PROCEEDED', 'SKIPPED'], $results);
        // The probe's simulated work is a 150ms sleep AFTER a successful
        // claim. If BOTH processes had entered that work, two overlapping
        // 150ms sleeps still finish in ~150ms (they run in parallel) — so
        // this alone doesn't distinguish "one skipped" from "both did the
        // work"; what it rules out is a THIRD, slower shape (e.g. the
        // skip path accidentally also sleeping). The real proof that the
        // loser never calls checkoutCart() is structural: SKIPPED is
        // printed immediately after the claim query returns 0 rows
        // affected, before the sleep line in stripe-claim-probe.php is
        // ever reached — confirmed by reading that fixture's own source,
        // which contains no sleep on the SKIPPED path at all.
        assert_true($elapsed < 1.0, 'the race must resolve quickly — a stuck/blocked loser would indicate the claim query itself is misbehaving');
    } finally {
        swrt_clean($rowId);
    }
});

unit('C1 fix: an already-completed row remains a no-op', function (): void {
    $rowId = swrt_seed('completed', 555);
    try {
        $result = swrt_probe_wait(swrt_probe_async('claim', $rowId, 9401));
        assert_eq('SKIPPED', $result, 'a row that already has an order_id must never be reclaimed');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(555, (int) $row['order_id'], 'the original order_id must be untouched');
        assert_eq('completed', $row['status'], 'the original status must be untouched');
    } finally {
        swrt_clean($rowId);
    }
});

unit('C1 fix: a checkout failure releases the claim back to pending', function (): void {
    $rowId = swrt_seed('pending');
    try {
        $result = swrt_probe_wait(swrt_probe_async('claim', $rowId, 9501, failAfterClaim: true));
        assert_eq('RELEASED', $result);

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(null, $row['order_id'], 'order_id must remain unset after a released claim');
        assert_eq('pending', $row['status'], 'status must be restored to pending, not left at claimed');
    } finally {
        swrt_clean($rowId);
    }
});

unit('C1 fix: a subsequent retry can successfully reclaim a row released after a prior failure', function (): void {
    $rowId = swrt_seed('pending');
    try {
        $failed = swrt_probe_wait(swrt_probe_async('claim', $rowId, 9601, failAfterClaim: true));
        assert_eq('RELEASED', $failed, 'setup: the first attempt must fail and release');

        $retried = swrt_probe_wait(swrt_probe_async('claim', $rowId, 9602));
        assert_eq('PROCEEDED', $retried, 'a retry after a released claim must be able to complete normally');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(9602, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        swrt_clean($rowId);
    }
});

unit('C1 fix: plugins/stripe-payment/public/webhook.php ships the atomic claim before ShopAPI::checkoutCart(), and releases it on both failure paths', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/webhook.php');

    $orderIdCheckPos = strpos($src, "if (!empty(\$row['order_id'])) {");
    $claimPos        = strpos($src, "'status' => 'claimed'");
    $checkoutCallPos = strpos($src, 'ShopAPI::checkoutCart($row');
    $throwCatchPos   = strpos($src, "catch (\\Throwable \$e) {\n        slate_log('Stripe webhook checkoutCart threw:");
    $notOkCheckPos   = strpos($src, "if (empty(\$res['ok']) || empty(\$res['order_id'])) {");
    $finalWritePos   = strpos($src, "'status'       => 'completed',");
    $releaseFnPos    = strpos($src, 'function releaseClaim(int $rowId): void {');

    assert_true($orderIdCheckPos !== false, 'the existing already-processed check must still be present');
    assert_true($claimPos !== false, 'the atomic claim must be present');
    assert_true($checkoutCallPos !== false, 'the checkoutCart() call must still be present');
    assert_true($throwCatchPos !== false, 'the checkoutCart() throw-catch must still be present');
    assert_true($notOkCheckPos !== false, 'the !ok result check must still be present');
    assert_true($finalWritePos !== false, 'the final completion write must still be present');
    assert_true($releaseFnPos !== false, 'the releaseClaim() helper must be present');

    assert_true($orderIdCheckPos < $claimPos, 'the claim must come after the existing already-processed check (never re-claim an already-completed row)');
    assert_true($claimPos < $checkoutCallPos, 'the claim must happen BEFORE checkoutCart() is ever called — that is the entire point');

    // Both failure branches between the claim and the final success write
    // must call releaseClaim() — find each branch's own body and confirm.
    $throwBranch = substr($src, $throwCatchPos, ($notOkCheckPos - $throwCatchPos));
    assert_true(str_contains($throwBranch, 'releaseClaim('), 'the checkoutCart()-threw branch must release the claim');

    $notOkBranchEnd = strpos($src, '$orderId = (int)$res', $notOkCheckPos);
    $notOkBranch = substr($src, $notOkCheckPos, ($notOkBranchEnd !== false ? $notOkBranchEnd - $notOkCheckPos : 400));
    assert_true(str_contains($notOkBranch, 'releaseClaim('), 'the !ok-result branch must release the claim');
});

unit('create-intent.php reuse-lookup: a row mid-claim is correctly excluded; a genuinely pending row is not (no regression)', function (): void {
    $sid = bin2hex(random_bytes(16));

    $claimedId = Database::insert('stripepayment_sessions', [
        'stripe_session_id' => null, 'payment_intent_id' => 'pi_test_' . bin2hex(random_bytes(6)),
        'shop_sid' => $sid, 'flow' => 'embedded', 'order_id' => null, 'status' => 'claimed',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $pendingId = Database::insert('stripepayment_sessions', [
        'stripe_session_id' => null, 'payment_intent_id' => 'pi_test_' . bin2hex(random_bytes(6)),
        'shop_sid' => $sid, 'flow' => 'embedded', 'order_id' => null, 'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        // The exact reuse-lookup query from plugins/stripe-payment/public/create-intent.php.
        $reuseQuery = "SELECT id, payment_intent_id
                          FROM stripepayment_sessions
                         WHERE shop_sid = ?
                           AND flow = 'embedded'
                           AND status = 'pending'
                           AND order_id IS NULL
                           AND payment_intent_id IS NOT NULL
                      ORDER BY id DESC
                         LIMIT 1";

        $found = Database::row($reuseQuery, [$sid]);
        assert_true($found !== null, 'the genuinely pending row must still be found (no regression from adding the claimed state)');
        assert_eq($pendingId, (int) $found['id'], 'the row returned must be the pending one, never the mid-claim one');
    } finally {
        Database::query('DELETE FROM stripepayment_sessions WHERE id IN (?, ?)', [$claimedId, $pendingId]);
    }
});
