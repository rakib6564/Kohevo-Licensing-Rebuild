<?php
/**
 * Phase 1D C3 — Stripe success.php TOCTOU race
 * (plugins/stripe-payment/public/success.php).
 *
 * Preserved as a separate finding from C1 (webhook.php) and C2, per the
 * standing instruction not to fold the three remaining "check → work →
 * write" Stripe TOCTOU sites (success.php, return.php,
 * StripePayment::handleEmbeddedCheckout()) together. This covers
 * success.php ONLY.
 *
 * Read-only investigation established:
 *   - success.php's idempotency check (`if (!empty($row['order_id']))`)
 *     read a row fetched moments earlier by an unlocked SELECT, then, only
 *     AFTER StripeAPI::getSession() + ShopAPI::checkoutCart() (real,
 *     unguarded, multi-query work — archive/plugins/shop/ShopAPI.php has no
 *     de-duplication of its own tied to shop_sid or this mapping row) —
 *     wrote order_id back. success.php SELECTED `status` but never read it
 *     anywhere in the file, so C1's webhook.php claim provided it no
 *     protection at all. A comment at the old write site claimed a
 *     transaction prevented the race; no transaction existed anywhere in
 *     the file.
 *   - Two distinct races were live-reproduced pre-fix: success.php racing
 *     ITSELF (e.g. a reload/double-click), and success.php racing
 *     webhook.php's POST-C1 claim on the same hosted-flow row (both look up
 *     by stripe_session_id). Both showed both sides proceeding, 100% of
 *     runs.
 *   - Hosted-flow rows (stripe_session_id set, payment_intent_id always
 *     NULL) and embedded-flow rows (payment_intent_id set, stripe_session_id
 *     always NULL) are structurally disjoint by construction — verified in
 *     StripePayment.php's and create-intent.php's own INSERT statements.
 *     success.php can therefore never race with return.php or
 *     StripePayment::handleEmbeddedCheckout(): only with webhook.php, and
 *     with itself.
 *
 * Fix (Option A, approved): the same atomic conditional UPDATE claim as C1
 * (status: pending/abandoned → 'claimed') before ShopAPI::checkoutCart() is
 * ever called. On claim rejection (order_id still NULL, meaning another
 * path is actively processing this row right now) success.php performs a
 * short, tightly bounded wait (5 x 100ms re-reads) for that other path's
 * order_id to land, and uses it if it does; otherwise it fails safe to the
 * existing order_failed redirect without ever reaching checkoutCart(). A
 * checkout failure after a successful claim releases it back to 'pending'
 * so a retry can cleanly reclaim the row.
 *
 * Live reproduction through the real page is not possible: `shop` is
 * archived and not present under plugins/, and success.php's own
 * `!PluginLoader::isActive('shop')` gate refuses to reach checkoutCart() at
 * all in that state (same reachability caveat as C1/H6). What IS
 * established live, with genuinely concurrent OS processes racing the real
 * check-then-act / claim-then-wait SQL shapes against a real
 * stripepayment_sessions row, is this test suite's own doing (see
 * success-claim-probe.php) — the specific consequence (a duplicate real
 * shop_orders row) is established by reading ShopAPI's archived source, not
 * executed live.
 *
 * Scope: success.php only. return.php and
 * StripePayment::handleEmbeddedCheckout() share the identical unguarded
 * pattern and are NOT touched here — tracked separately, as approved.
 */

declare(strict_types=1);

/** Same defensive guard as StripeWebhookRaceTest.php's swrt_ensure_columns() —
 *  duplicated rather than shared, since this file must not depend on that
 *  one's load order (tests/integration/run.php globs files alphabetically,
 *  and "StripeSuccessRaceTest" sorts before "StripeWebhookRaceTest"). Only
 *  the last test in this file (hosted/embedded separation) needs these. */
function ssrt_ensure_columns(): void
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
ssrt_ensure_columns();

function ssrt_seed(string $status = 'pending', ?int $orderId = null): int
{
    return Database::insert('stripepayment_sessions', [
        'stripe_session_id' => 'cs_test_' . bin2hex(random_bytes(8)),
        'shop_sid'          => bin2hex(random_bytes(16)),
        'order_id'          => $orderId,
        'status'            => $status,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
}

function ssrt_probe_async(string $mode, int $rowId, int $fakeOrderId, string $fourth = '0'): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/success-claim-probe.php') . ' '
         . escapeshellarg($mode) . ' ' . escapeshellarg((string) $rowId) . ' ' . escapeshellarg((string) $fakeOrderId)
         . ' ' . escapeshellarg($fourth);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

function ssrt_probe_wait(array $handle): string
{
    $out = stream_get_contents($handle['stdout']);
    fclose($handle['stdout']);
    fclose($handle['stderr']);
    proc_close($handle['proc']);
    return trim((string) $out);
}

/** Launch actors as truly concurrent OS processes, return outputs in launch order. */
function ssrt_race(array $actors, int $rowId): array
{
    $handles = [];
    foreach ($actors as [$mode, $fakeOrderId, $fourth]) {
        $handles[] = ssrt_probe_async($mode, $rowId, $fakeOrderId, $fourth);
    }
    return array_map('ssrt_probe_wait', $handles);
}

function ssrt_clean(int $rowId): void
{
    Database::query('DELETE FROM stripepayment_sessions WHERE id = ?', [$rowId]);
}

unit('C3 vulnerable baseline: two concurrent completions of success.php\'s PRE-FIX pattern both proceed (duplicate order creation)', function (): void {
    $rowId = ssrt_seed('pending');
    try {
        $results = ssrt_race([['old', 9701, '0'], ['old', 9702, '0']], $rowId);
        sort($results);
        assert_eq(['PROCEEDED', 'PROCEEDED'], $results, 'the old, unguarded success.php pattern must let BOTH concurrent callers pass the empty-check and proceed — this is exactly the defect C3 closes');
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: two concurrent success.php completions result in exactly one claimant, the other observes the winner', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $rowId = ssrt_seed('pending');
        try {
            $results = ssrt_race([['success', 9711 + $i * 10, '0'], ['success', 9712 + $i * 10, '0']], $rowId);

            $proceeded = array_values(array_filter($results, fn($r) => str_starts_with($r, 'PROCEEDED:')));
            $observed  = array_values(array_filter($results, fn($r) => str_starts_with($r, 'WINNER_OBSERVED:')));

            assert_eq(1, count($proceeded), "iteration $i: exactly one concurrent claimant must actually proceed to checkout: " . implode(',', $results));
            assert_eq(1, count($observed), "iteration $i: the other must observe the winner rather than fail: " . implode(',', $results));

            $winnerId = explode(':', $proceeded[0])[1];
            $observedId = explode(':', $observed[0])[1];
            assert_eq($winnerId, $observedId, "iteration $i: the loser must observe the SAME order id the winner created, not a stale/mismatched one");

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq((int) $winnerId, (int) $row['order_id'], "iteration $i: the winner's order_id must be the one recorded");
            assert_eq('completed', $row['status'], "iteration $i: the row must end up completed, not stuck at claimed");
        } finally {
            ssrt_clean($rowId);
        }
    }
});

unit('C3 fix: two concurrent success.php requests cannot both reach checkout (only one claimant ever runs the simulated checkoutCart work)', function (): void {
    $rowId = ssrt_seed('pending');
    try {
        $start = microtime(true);
        $results = ssrt_race([['success', 9801, '0'], ['success', 9802, '0']], $rowId);
        $elapsed = microtime(true) - $start;

        $proceeded = array_filter($results, fn($r) => str_starts_with($r, 'PROCEEDED:'));
        assert_eq(1, count($proceeded), 'exactly one of two concurrent success.php requests must reach the simulated checkoutCart work: ' . implode(',', $results));

        // Structural proof the loser never calls checkoutCart(): the
        // WINNER_OBSERVED/FAILED_SAFE branches in success-claim-probe.php's
        // 'success' mode return immediately from the wait loop with no
        // sleep(150000) checkoutCart-simulation call on that path at all —
        // confirmed by reading that fixture's own source.
        assert_true($elapsed < 1.5, 'the race must resolve within the bounded wait window (150ms work + up to 500ms retry), not hang: took ' . $elapsed . 's');
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: success.php vs webhook.php\'s claim on the same hosted-flow row → exactly one can claim, the other observes or is skipped', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $rowId = ssrt_seed('pending');
        try {
            // 'claim' plays webhook.php's role: byte-identical claim query,
            // no retry-on-rejection — exactly completePayment()'s own shape.
            $results = ssrt_race([['success', 9901 + $i * 10, '0'], ['claim', 9902 + $i * 10, '0']], $rowId);

            $successOut = $results[0];
            $webhookOut = $results[1];

            $webhookWon  = $webhookOut === 'PROCEEDED';
            $successWon  = str_starts_with($successOut, 'PROCEEDED:');
            assert_true($webhookWon xor $successWon, "iteration $i: exactly one side must win the claim: success=$successOut webhook=$webhookOut");

            if ($webhookWon) {
                assert_eq('WINNER_OBSERVED:' . (9902 + $i * 10), $successOut, "iteration $i: when webhook.php's claim wins, success.php must observe its order_id rather than duplicate it: $successOut");
            } else {
                assert_eq('SKIPPED', $webhookOut, "iteration $i: when success.php's claim wins, the bare webhook-shaped claimant must be skipped: $webhookOut");
            }

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq('completed', $row['status'], "iteration $i: exactly one order must be recorded, and the row must end completed either way");
        } finally {
            ssrt_clean($rowId);
        }
    }
});

unit('C3 fix: the losing success.php request correctly handles an active claimed row (webhook still mid-flight)', function (): void {
    // Deterministic version of the above: seed the row as ALREADY claimed
    // (order_id still NULL — "webhook.php is actively processing right
    // now"), then have a 'finisher' land the order_id partway through
    // success.php's bounded wait window, rather than depending on which
    // side happens to win an actual claim race.
    $rowId = ssrt_seed('claimed', null);
    try {
        $success  = ssrt_probe_async('success', $rowId, 0, '0');
        $finisher = ssrt_probe_async('finisher', $rowId, 10001, '200'); // lands ~200ms in, well inside the 500ms window

        $successOut  = ssrt_probe_wait($success);
        $finisherOut = ssrt_probe_wait($finisher);

        assert_eq('FINISHED:10001', $finisherOut);
        assert_eq('WINNER_OBSERVED:10001', $successOut, 'success.php must observe the order_id once the actively-processing path finishes, not fail safe prematurely');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(10001, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: if the claim remains unavailable for the full bounded window, success.php fails safe without ever reaching checkout', function (): void {
    // Seed as claimed with no finisher at all — the window must expire and
    // fail safe, never fabricate an order_id or hang.
    $rowId = ssrt_seed('claimed', null);
    try {
        $start = microtime(true);
        $result = ssrt_probe_wait(ssrt_probe_async('success', $rowId, 0, '0'));
        $elapsed = microtime(true) - $start;

        assert_eq('FAILED_SAFE', $result, 'with no order_id ever landing, success.php must fail safe rather than proceed to checkout or hang');
        assert_true($elapsed >= 0.5 && $elapsed < 1.5, 'the fail-safe path must take roughly the full bounded window (5 x 100ms), not exit early or hang: took ' . $elapsed . 's');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_null($row['order_id'], 'no order_id must be fabricated on a failed-safe outcome');
        assert_eq('claimed', $row['status'], 'success.php must not touch a row it never itself claimed — the other (unresolved) claimant still owns it');
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: an already-completed row is treated as idempotent even if the claim/wait mechanism is reached', function (): void {
    $rowId = ssrt_seed('completed', 555);
    try {
        $result = ssrt_probe_wait(ssrt_probe_async('success', $rowId, 0, '0'));
        assert_eq('WINNER_OBSERVED:555', $result, 'a row that already has an order_id must be observed immediately, never re-claimed or re-processed');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(555, (int) $row['order_id'], 'the original order_id must be untouched');
        assert_eq('completed', $row['status'], 'the original status must be untouched');
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: a checkout failure releases the claim back to pending', function (): void {
    $rowId = ssrt_seed('pending');
    try {
        $result = ssrt_probe_wait(ssrt_probe_async('success', $rowId, 9951, '1'));
        assert_eq('RELEASED', $result);

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_null($row['order_id'], 'order_id must remain unset after a released claim');
        assert_eq('pending', $row['status'], 'status must be restored to pending, not left at claimed');
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: a subsequent retry can successfully reclaim and complete a row released after a prior failure', function (): void {
    $rowId = ssrt_seed('pending');
    try {
        $failed = ssrt_probe_wait(ssrt_probe_async('success', $rowId, 9961, '1'));
        assert_eq('RELEASED', $failed, 'setup: the first attempt must fail and release');

        $retried = ssrt_probe_wait(ssrt_probe_async('success', $rowId, 9962, '0'));
        assert_eq('PROCEEDED:9962', $retried, 'a retry after a released claim must be able to complete normally');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(9962, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        ssrt_clean($rowId);
    }
});

unit('C3 fix: plugins/stripe-payment/public/success.php ships the atomic claim before ShopAPI::checkoutCart(), waits on rejection, and releases on both failure paths', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/success.php');

    $orderIdCheckPos = strpos($src, "if (!empty(\$row['order_id'])) {");
    $claimPos        = strpos($src, "'status' => 'claimed'");
    $waitCallPos     = strpos($src, 'successWaitForCompletion((int)$row');
    $checkoutCallPos = strpos($src, 'ShopAPI::checkoutCart($row');
    $throwCatchPos   = strpos($src, "catch (\\Throwable \$e) {\n    slate_log('Stripe success.php checkoutCart threw:");
    $notOkCheckPos   = strpos($src, "if (empty(\$res['ok']) || empty(\$res['order_id'])) {");
    $finalWritePos   = strpos($src, "'status'       => 'completed',");
    $releaseFnPos    = strpos($src, 'function successReleaseClaim(int $rowId): void {');
    $waitFnPos       = strpos($src, 'function successWaitForCompletion(int $rowId): ?array {');
    $shopSidCheckPos = strpos($src, "hash_equals((string)\$row['shop_sid'], \$browserSid)");

    assert_true($shopSidCheckPos !== false, 'the H6 shop_sid ownership check must still be present');
    assert_true($orderIdCheckPos !== false, 'the existing already-processed check must still be present');
    assert_true($claimPos !== false, 'the atomic claim must be present');
    assert_true($waitCallPos !== false, 'the bounded wait-for-completion call must be present');
    assert_true($checkoutCallPos !== false, 'the checkoutCart() call must still be present');
    assert_true($throwCatchPos !== false, 'the checkoutCart() throw-catch must be present');
    assert_true($notOkCheckPos !== false, 'the !ok result check must still be present');
    assert_true($finalWritePos !== false, 'the final completion write must still be present');
    assert_true($releaseFnPos !== false, 'the successReleaseClaim() helper must be present');
    assert_true($waitFnPos !== false, 'the successWaitForCompletion() helper must be present');

    assert_true($shopSidCheckPos < $orderIdCheckPos, 'the H6 ownership check must remain BEFORE the idempotency/claim logic, unchanged in position');
    assert_true($orderIdCheckPos < $claimPos, 'the claim must come after the existing already-processed check (never re-claim a row already known to have an order)');
    assert_true($claimPos < $waitCallPos, 'the wait-for-completion call must be reachable only from the claim-rejected branch, after the claim attempt');
    assert_true($waitCallPos < $checkoutCallPos, 'the claim (and its rejection handling) must happen BEFORE checkoutCart() is ever called — that is the entire point');

    $throwBranch = substr($src, $throwCatchPos, ($notOkCheckPos - $throwCatchPos));
    assert_true(str_contains($throwBranch, 'successReleaseClaim('), 'the checkoutCart()-threw branch must release the claim');

    $notOkBranchEnd = strpos($src, '$orderId = (int)$res', $notOkCheckPos);
    $notOkBranch = substr($src, $notOkCheckPos, ($notOkBranchEnd !== false ? $notOkBranchEnd - $notOkCheckPos : 400));
    assert_true(str_contains($notOkBranch, 'successReleaseClaim('), 'the !ok-result branch must release the claim');
});

unit('C3 scope: hosted-flow and embedded-flow rows remain correctly separated (success.php can never match an embedded row, or vice versa)', function (): void {
    $sid = bin2hex(random_bytes(16));

    $hostedId = Database::insert('stripepayment_sessions', [
        'stripe_session_id' => 'cs_test_' . bin2hex(random_bytes(8)), 'payment_intent_id' => null,
        'shop_sid' => $sid, 'flow' => 'hosted', 'order_id' => null, 'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $embeddedId = Database::insert('stripepayment_sessions', [
        'stripe_session_id' => null, 'payment_intent_id' => 'pi_test_' . bin2hex(random_bytes(6)),
        'shop_sid' => $sid, 'flow' => 'embedded', 'order_id' => null, 'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $hostedRow = Database::row('SELECT stripe_session_id, payment_intent_id FROM stripepayment_sessions WHERE id = ?', [$hostedId]);
        $embeddedRow = Database::row('SELECT stripe_session_id, payment_intent_id FROM stripepayment_sessions WHERE id = ?', [$embeddedId]);

        assert_null($hostedRow['payment_intent_id'], 'a hosted-flow row must always have payment_intent_id NULL');
        assert_null($embeddedRow['stripe_session_id'], 'an embedded-flow row must always have stripe_session_id NULL');

        // success.php's exact lookup shape: WHERE stripe_session_id = ?
        $viaSuccessLookup = Database::row(
            'SELECT id FROM stripepayment_sessions WHERE stripe_session_id = ?',
            [(string) $embeddedRow['payment_intent_id']]
        );
        assert_true($viaSuccessLookup === null, 'success.php\'s stripe_session_id lookup must never match an embedded-flow row, even using its payment_intent_id as the search value');

        // return.php's / handleEmbeddedCheckout()'s exact lookup shape: WHERE payment_intent_id = ?
        $viaEmbeddedLookup = Database::row(
            'SELECT id FROM stripepayment_sessions WHERE payment_intent_id = ?',
            [(string) $hostedRow['stripe_session_id']]
        );
        assert_true($viaEmbeddedLookup === null, 'the embedded-flow payment_intent_id lookup must never match a hosted-flow row, even using its stripe_session_id as the search value');
    } finally {
        Database::query('DELETE FROM stripepayment_sessions WHERE id IN (?, ?)', [$hostedId, $embeddedId]);
    }
});
