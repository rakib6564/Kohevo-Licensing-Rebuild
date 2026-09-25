<?php
/**
 * Phase 1D C4 — Stripe return.php TOCTOU race
 * (plugins/stripe-payment/public/return.php).
 *
 * Preserved as a separate finding from C1 (webhook.php) and C3
 * (success.php), per the standing instruction not to fold the three
 * remaining "check → work → write" Stripe TOCTOU sites (success.php,
 * return.php, StripePayment::handleEmbeddedCheckout()) together. This
 * covers return.php ONLY.
 *
 * Read-only investigation established:
 *   - return.php's idempotency check (`if (!empty($row['order_id']))`)
 *     read a row fetched moments earlier by an unlocked SELECT, then, only
 *     AFTER StripeAPI::getPaymentIntent() + ShopAPI::checkoutCart() (real,
 *     unguarded, multi-query work — no de-duplication of its own) — wrote
 *     order_id back. return.php SELECTED `status` but never read it
 *     anywhere in the file, so C1's webhook.php claim provided it no
 *     protection at all — the identical defect C3 found in success.php.
 *   - The file's own docblock claimed "we wait a moment and check again"
 *     if the webhook hadn't fired yet; no such wait/recheck existed
 *     anywhere in the code before this fix.
 *   - Every competing path that can process the SAME payment_intent_id row
 *     was enumerated by grepping every checkoutCart() call site in the
 *     plugin (exactly 4 exist project-wide): webhook.php's
 *     payment_intent.succeeded handler (fixed, C1), return.php itself
 *     (self-race), and StripePayment::handleEmbeddedCheckout() (NOT fixed
 *     here — out of scope for C4, tracked as C5). success.php cannot reach
 *     this row: hosted and embedded rows are structurally disjoint
 *     (established in C3).
 *   - Three races were live-reproduced pre-fix: return.php vs ITSELF,
 *     return.php vs webhook.php's shipped C1 claim, and return.php vs
 *     handleEmbeddedCheckout(). All three showed both sides proceeding,
 *     100% of runs.
 *   - Reproducing the PROPOSED fix live showed it closes the self-race and
 *     the race against webhook.php's claim, but — as predicted from
 *     reading handleEmbeddedCheckout()'s source, which never reads status
 *     — does NOT close the race against handleEmbeddedCheckout(): both
 *     sides still proceeded, 100% of runs, even with the fix in place. No
 *     return.php-side mechanism (claim, transaction, or lock) can close
 *     that pair: handleEmbeddedCheckout()'s own checkoutCart() call isn't
 *     gated by anything that would observe return.php's claim.
 *
 * Fix (Option A, approved): the same atomic conditional UPDATE claim as
 * C1/C3 (status: pending/abandoned → 'claimed') before
 * ShopAPI::checkoutCart() is ever called. On claim rejection (order_id
 * still NULL, meaning another path is actively processing this row right
 * now) return.php performs the same short, tightly bounded wait as C3 (5 x
 * 100ms re-reads) for that other path's order_id to land, and uses it if
 * it does; otherwise it fails safe to the existing order_failed redirect
 * without ever reaching checkoutCart(). A checkout failure after a
 * successful claim releases it back to 'pending' so a retry can cleanly
 * reclaim the row.
 *
 * KNOWN RESIDUAL GAP, explicitly accepted for C4: this fix does NOT close
 * the return.php vs handleEmbeddedCheckout() race — that pair remains
 * exactly as exposed as before this fix, tracked as its own finding (C5),
 * which must use the same claim semantics so all competing embedded-flow
 * paths converge on one idempotency mechanism. The tests below include a
 * dedicated demonstration of this residual gap, using
 * handleEmbeddedCheckout()'s ACTUAL current shape (mirrored in
 * return-claim-probe.php's 'embedded' mode) — WITHOUT modifying that
 * function, which stays untouched in C4.
 *
 * Live reproduction through the real page is not possible: `shop` is
 * archived and not present under plugins/, and return.php's own
 * `!PluginLoader::isActive('shop')` gate refuses to reach checkoutCart() at
 * all in that state (same reachability caveat as C1/C3/H6). What IS
 * established live, with genuinely concurrent OS processes racing the real
 * check-then-act / claim-then-wait SQL shapes against a real
 * stripepayment_sessions row, is this test suite's own doing (see
 * return-claim-probe.php) — the specific consequence (a duplicate real
 * shop_orders row) is established by reading ShopAPI's archived source,
 * not executed live.
 *
 * Scope: return.php only. StripePayment::handleEmbeddedCheckout() is NOT
 * touched here — tracked separately as C5, as approved.
 */

declare(strict_types=1);

/** Same defensive guard as StripeWebhookRaceTest.php's swrt_ensure_columns()
 *  / StripeSuccessRaceTest.php's ssrt_ensure_columns() — duplicated rather
 *  than shared, since this file must not depend on load order (tests/
 *  integration/run.php globs files alphabetically, and
 *  "StripeReturnRaceTest" sorts before both of those). This file's rows
 *  need payment_intent_id/flow from the moment they're seeded. */
function srrt_ensure_columns(): void
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
srrt_ensure_columns();

function srrt_seed(string $status = 'pending', ?int $orderId = null): int
{
    return Database::insert('stripepayment_sessions', [
        'stripe_session_id' => null,
        'payment_intent_id' => 'pi_test_' . bin2hex(random_bytes(8)),
        'shop_sid'          => bin2hex(random_bytes(16)),
        'flow'              => 'embedded',
        'order_id'          => $orderId,
        'status'            => $status,
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
}

function srrt_probe_async(string $mode, int $rowId, int $fakeOrderId, string $fourth = '0'): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/return-claim-probe.php') . ' '
         . escapeshellarg($mode) . ' ' . escapeshellarg((string) $rowId) . ' ' . escapeshellarg((string) $fakeOrderId)
         . ' ' . escapeshellarg($fourth);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

function srrt_probe_wait(array $handle): string
{
    $out = stream_get_contents($handle['stdout']);
    fclose($handle['stdout']);
    fclose($handle['stderr']);
    proc_close($handle['proc']);
    return trim((string) $out);
}

/** Launch actors as truly concurrent OS processes, return outputs in launch order. */
function srrt_race(array $actors, int $rowId): array
{
    $handles = [];
    foreach ($actors as [$mode, $fakeOrderId, $fourth]) {
        $handles[] = srrt_probe_async($mode, $rowId, $fakeOrderId, $fourth);
    }
    return array_map('srrt_probe_wait', $handles);
}

function srrt_clean(int $rowId): void
{
    Database::query('DELETE FROM stripepayment_sessions WHERE id = ?', [$rowId]);
}

unit('C4 vulnerable baseline: two concurrent completions of return.php\'s PRE-FIX pattern both proceed (duplicate order creation)', function (): void {
    $rowId = srrt_seed('pending');
    try {
        $results = srrt_race([['old', 8801, '0'], ['old', 8802, '0']], $rowId);
        sort($results);
        assert_eq(['PROCEEDED', 'PROCEEDED'], $results, 'the old, unguarded return.php pattern must let BOTH concurrent callers pass the empty-check and proceed — this is exactly the defect C4 closes');
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 vulnerable baseline: return.php\'s PRE-FIX pattern racing webhook.php\'s shipped C1 claim — the claim gives it no protection', function (): void {
    $rowId = srrt_seed('pending');
    try {
        $results = srrt_race([['old', 8901, '0'], ['claim', 8902, '0']], $rowId);
        sort($results);
        assert_eq(['PROCEEDED', 'PROCEEDED'], $results, 'return.php (pre-fix) selects but never reads status, so it must proceed even while webhook.php holds/wins the claim — confirming the same defect C3 found in success.php');
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 fix: two concurrent return.php completions result in exactly one claimant, the other observes the winner', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $rowId = srrt_seed('pending');
        try {
            $results = srrt_race([['return', 8911 + $i * 10, '0'], ['return', 8912 + $i * 10, '0']], $rowId);

            $proceeded = array_values(array_filter($results, fn($r) => str_starts_with($r, 'PROCEEDED:')));
            $observed  = array_values(array_filter($results, fn($r) => str_starts_with($r, 'WINNER_OBSERVED:')));

            assert_eq(1, count($proceeded), "iteration $i: exactly one concurrent claimant must actually proceed to checkout: " . implode(',', $results));
            assert_eq(1, count($observed), "iteration $i: the other must observe the winner rather than fail: " . implode(',', $results));

            $winnerId = explode(':', $proceeded[0])[1];
            $observedId = explode(':', $observed[0])[1];
            assert_eq($winnerId, $observedId, "iteration $i: the loser must observe the SAME order id the winner created");

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq((int) $winnerId, (int) $row['order_id'], "iteration $i: the winner's order_id must be the one recorded");
            assert_eq('completed', $row['status'], "iteration $i: the row must end up completed, not stuck at claimed");
        } finally {
            srrt_clean($rowId);
        }
    }
});

unit('C4 fix: return.php vs webhook.php\'s shipped claim on the same embedded-flow row → exactly one can claim, the other observes or is skipped', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $rowId = srrt_seed('pending');
        try {
            // 'claim' plays webhook.php's role: byte-identical claim query,
            // no retry-on-rejection — exactly completePayment()'s own shape.
            $results = srrt_race([['return', 8921 + $i * 10, '0'], ['claim', 8922 + $i * 10, '0']], $rowId);

            $returnOut  = $results[0];
            $webhookOut = $results[1];

            $webhookWon = $webhookOut === 'PROCEEDED';
            $returnWon  = str_starts_with($returnOut, 'PROCEEDED:');
            assert_true($webhookWon xor $returnWon, "iteration $i: exactly one side must win the claim: return=$returnOut webhook=$webhookOut");

            if ($webhookWon) {
                assert_eq('WINNER_OBSERVED:' . (8922 + $i * 10), $returnOut, "iteration $i: when webhook.php's claim wins, return.php must observe its order_id rather than duplicate it: $returnOut");
            } else {
                assert_eq('SKIPPED', $webhookOut, "iteration $i: when return.php's claim wins, the bare webhook-shaped claimant must be skipped: $webhookOut");
            }

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq('completed', $row['status'], "iteration $i: exactly one order must be recorded, and the row must end completed either way");
        } finally {
            srrt_clean($rowId);
        }
    }
});

unit('C4 KNOWN RESIDUAL GAP: return.php\'s fix does NOT protect against handleEmbeddedCheckout() (unmodified) — both still reach checkout', function (): void {
    // Demonstrates, without touching StripePayment::handleEmbeddedCheckout()
    // at all, that the C4 fix leaves this specific pair open — exactly as
    // predicted and explicitly accepted per the approved design. 'embedded'
    // mode is handleEmbeddedCheckout()'s real, current, unmodified shape.
    for ($i = 0; $i < 4; $i++) {
        $rowId = srrt_seed('pending');
        try {
            $results = srrt_race([['return', 8931 + $i * 10, '0'], ['embedded', 8932 + $i * 10, '0']], $rowId);
            sort($results);
            assert_true(
                in_array('PROCEEDED', $results, true) || preg_grep('/^PROCEEDED:/', $results) !== [],
                "iteration $i: sanity — at least one side must proceed: " . implode(',', $results)
            );
            // The defect: BOTH sides proceed to checkout, not just one.
            // return.php's own claim succeeds (there is nothing preventing
            // it), and handleEmbeddedCheckout() — never reading status —
            // independently proceeds too, since it only checks order_id
            // via its own stale, unlocked SELECT.
            $returnProceeded   = preg_grep('/^PROCEEDED:/', $results) !== [];
            $embeddedProceeded = in_array('PROCEEDED', $results, true);
            assert_true($returnProceeded && $embeddedProceeded, "iteration $i: KNOWN GAP — both return.php's fix AND the unmodified handleEmbeddedCheckout() must still independently proceed to checkout, proving this pair is not yet closed: " . implode(',', $results));
        } finally {
            srrt_clean($rowId);
        }
    }
});

unit('C4 fix: the losing return.php request correctly handles an active claimed row (webhook still mid-flight)', function (): void {
    // Deterministic version of the webhook-race test above: seed the row as
    // ALREADY claimed (order_id still NULL — "webhook.php is actively
    // processing right now"), then have a 'finisher' land the order_id
    // partway through return.php's bounded wait window.
    $rowId = srrt_seed('claimed', null);
    try {
        $return   = srrt_probe_async('return', $rowId, 0, '0');
        $finisher = srrt_probe_async('finisher', $rowId, 10001, '200'); // lands ~200ms in, inside the 500ms window

        $returnOut   = srrt_probe_wait($return);
        $finisherOut = srrt_probe_wait($finisher);

        assert_eq('FINISHED:10001', $finisherOut);
        assert_eq('WINNER_OBSERVED:10001', $returnOut, 'return.php must observe the order_id once the actively-processing path finishes, not fail safe prematurely');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(10001, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 fix: if the claim remains unavailable for the full bounded window, return.php fails safe without ever reaching checkout', function (): void {
    $rowId = srrt_seed('claimed', null);
    try {
        $start = microtime(true);
        $result = srrt_probe_wait(srrt_probe_async('return', $rowId, 0, '0'));
        $elapsed = microtime(true) - $start;

        assert_eq('FAILED_SAFE', $result, 'with no order_id ever landing, return.php must fail safe rather than proceed to checkout or hang');
        assert_true($elapsed >= 0.5 && $elapsed < 1.5, 'the fail-safe path must take roughly the full bounded window (5 x 100ms), not exit early or hang: took ' . $elapsed . 's');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_null($row['order_id'], 'no order_id must be fabricated on a failed-safe outcome');
        assert_eq('claimed', $row['status'], 'return.php must not touch a row it never itself claimed — the other (unresolved) claimant still owns it');
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 fix: an already-completed row is treated as idempotent even if the claim/wait mechanism is reached', function (): void {
    $rowId = srrt_seed('completed', 555);
    try {
        $result = srrt_probe_wait(srrt_probe_async('return', $rowId, 0, '0'));
        assert_eq('WINNER_OBSERVED:555', $result, 'a row that already has an order_id must be observed immediately, never re-claimed or re-processed');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(555, (int) $row['order_id'], 'the original order_id must be untouched');
        assert_eq('completed', $row['status'], 'the original status must be untouched');
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 fix: a checkout failure releases the claim back to pending', function (): void {
    $rowId = srrt_seed('pending');
    try {
        $result = srrt_probe_wait(srrt_probe_async('return', $rowId, 9951, '1'));
        assert_eq('RELEASED', $result);

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_null($row['order_id'], 'order_id must remain unset after a released claim');
        assert_eq('pending', $row['status'], 'status must be restored to pending, not left at claimed');
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 fix: a subsequent retry can successfully reclaim and complete a row released after a prior failure', function (): void {
    $rowId = srrt_seed('pending');
    try {
        $failed = srrt_probe_wait(srrt_probe_async('return', $rowId, 9961, '1'));
        assert_eq('RELEASED', $failed, 'setup: the first attempt must fail and release');

        $retried = srrt_probe_wait(srrt_probe_async('return', $rowId, 9962, '0'));
        assert_eq('PROCEEDED:9962', $retried, 'a retry after a released claim must be able to complete normally');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(9962, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        srrt_clean($rowId);
    }
});

unit('C4 fix: plugins/stripe-payment/public/return.php ships the atomic claim before ShopAPI::checkoutCart(), waits on rejection, releases on both failure paths, and preserves the shop_sid ownership check', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/return.php');

    $orderIdCheckPos = strpos($src, "if (!empty(\$row['order_id'])) {");
    $claimPos        = strpos($src, "'status' => 'claimed'");
    $waitCallPos     = strpos($src, 'returnWaitForCompletion((int)$row');
    $checkoutCallPos = strpos($src, 'ShopAPI::checkoutCart($row');
    $throwCatchPos   = strpos($src, "catch (\\Throwable \$e) {\n    slate_log('Stripe return.php checkoutCart threw:");
    $notOkCheckPos   = strpos($src, "if (empty(\$res['ok']) || empty(\$res['order_id'])) {");
    $finalWritePos   = strpos($src, "'status'       => 'completed',");
    $releaseFnPos    = strpos($src, 'function returnReleaseClaim(int $rowId): void {');
    $waitFnPos       = strpos($src, 'function returnWaitForCompletion(int $rowId): ?array {');
    $shopSidCheckPos = strpos($src, "hash_equals((string)\$row['shop_sid'], \$browserSid)");

    assert_true($shopSidCheckPos !== false, 'the shop_sid ownership check must still be present');
    assert_true($orderIdCheckPos !== false, 'the existing already-processed check must still be present');
    assert_true($claimPos !== false, 'the atomic claim must be present');
    assert_true($waitCallPos !== false, 'the bounded wait-for-completion call must be present');
    assert_true($checkoutCallPos !== false, 'the checkoutCart() call must still be present');
    assert_true($throwCatchPos !== false, 'the checkoutCart() throw-catch must be present');
    assert_true($notOkCheckPos !== false, 'the !ok result check must still be present');
    assert_true($finalWritePos !== false, 'the final completion write must still be present');
    assert_true($releaseFnPos !== false, 'the returnReleaseClaim() helper must be present');
    assert_true($waitFnPos !== false, 'the returnWaitForCompletion() helper must be present');

    assert_true($shopSidCheckPos < $orderIdCheckPos, 'the ownership check must remain BEFORE the idempotency/claim logic, unchanged in position');
    assert_true($orderIdCheckPos < $claimPos, 'the claim must come after the existing already-processed check (never re-claim a row already known to have an order)');
    assert_true($claimPos < $waitCallPos, 'the wait-for-completion call must be reachable only from the claim-rejected branch, after the claim attempt');
    assert_true($waitCallPos < $checkoutCallPos, 'the claim (and its rejection handling) must happen BEFORE checkoutCart() is ever called — that is the entire point');

    $throwBranch = substr($src, $throwCatchPos, ($notOkCheckPos - $throwCatchPos));
    assert_true(str_contains($throwBranch, 'returnReleaseClaim('), 'the checkoutCart()-threw branch must release the claim');

    $notOkBranchEnd = strpos($src, '$orderId = (int)$res', $notOkCheckPos);
    $notOkBranch = substr($src, $notOkCheckPos, ($notOkBranchEnd !== false ? $notOkBranchEnd - $notOkCheckPos : 400));
    assert_true(str_contains($notOkBranch, 'returnReleaseClaim('), 'the !ok-result branch must release the claim');

    // Scope guard: this commit documents the residual gap in comments (see
    // the file docblock's KNOWN RESIDUAL GAP note — always written as the
    // bare, argument-less "handleEmbeddedCheckout()") but must not CALL it
    // or otherwise wire into it — that stays out of scope for C4. A real
    // call needs its ($sid, $billing) arguments and an object/class
    // reference, so check for that shape specifically rather than any
    // mention of the name.
    assert_true(!preg_match('/(->|::)\s*handleEmbeddedCheckout\s*\(\s*\$/', $src), 'return.php must not call handleEmbeddedCheckout() — that stays out of scope for C4 (mentioning it by name in a comment, e.g. to document the residual gap, is fine)');
});

unit('C4 scope: handleEmbeddedCheckout() still exists with its original signature (superseded check — see note)', function (): void {
    // At C4-commit-time this test additionally asserted that
    // handleEmbeddedCheckout() must NOT yet contain a claim mechanism —
    // a valid scope guard confirming the C4 commit hadn't accidentally
    // done C5's work. Phase 1D C5 has since deliberately and separately
    // added that exact mechanism to handleEmbeddedCheckout() (see
    // StripeEmbeddedCheckoutRaceTest.php), so that assertion is now
    // obsolete rather than a regression to guard against — removed here
    // rather than left to permanently fail. The signature check remains
    // meaningful: this file's own claim/wait logic (return.php) still
    // must not have been refactored into calling or wrapping
    // handleEmbeddedCheckout() directly.
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePayment.php');
    $fnPos = strpos($src, 'public function handleEmbeddedCheckout(string $sid, array $billing): array {');
    assert_true($fnPos !== false, 'handleEmbeddedCheckout() must still exist, with its original signature');
});
