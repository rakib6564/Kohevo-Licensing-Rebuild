<?php
/**
 * Phase 1D C5 — Stripe StripePayment::handleEmbeddedCheckout() TOCTOU race
 * (plugins/stripe-payment/StripePayment.php).
 *
 * The fourth and final of the four Stripe TOCTOU findings tracked
 * separately since C1 (webhook.php, fixed), C3 (success.php, fixed), and
 * C4 (return.php, fixed) — each of those explicitly preserved
 * handleEmbeddedCheckout() as its own finding rather than folding it in.
 * This covers handleEmbeddedCheckout() ONLY.
 *
 * Read-only investigation established:
 *   - handleEmbeddedCheckout() is registered as the 'stripe_embedded'
 *     provider's handle_checkout callback (StripePayment::registerProviders(),
 *     hooked to the shop_payment_providers filter) and is called from
 *     exactly one place project-wide: archive/plugins/shop/storefront/
 *     checkout.php's place_order POST handler — unreachable via live HTTP
 *     today since shop is archived (same caveat as C1/C3/C4).
 *   - Its idempotency check (`if (!empty($row['order_id']))`) read a row
 *     fetched moments earlier by an unlocked SELECT, then, only AFTER
 *     StripeAPI::getPaymentIntent() + ShopAPI::checkoutCart() (real,
 *     unguarded, multi-query work — no de-duplication of its own) — wrote
 *     order_id back. It selected `status` but never read it, so C1's
 *     webhook.php claim (and C4's return.php claim) gave it no protection
 *     at all — the identical defect found in success.php and return.php.
 *   - checkoutCart() was called completely unguarded (no try/catch) —
 *     once a claim is introduced, an uncaught exception there would have
 *     stranded the row at 'claimed' forever; this fix adds the same
 *     try/catch + release used by C1/C3/C4, which is necessary specifically
 *     BECAUSE the claim is being added here (before this fix, an uncaught
 *     exception simply fataled the request with nothing to leak).
 *   - Three races were live-reproduced pre-fix: handleEmbeddedCheckout()
 *     vs ITSELF (a double place-order submit), vs webhook.php's shipped
 *     C1 claim, and vs return.php's shipped C4 fix. All three showed both
 *     sides proceeding, 100% of runs — directly confirming C4's own
 *     "known residual gap" finding from this side.
 *   - Reproducing the PROPOSED fix live showed it closes all three: the
 *     self-race, the race against webhook.php's claim, AND — critically —
 *     the race against return.php's shipped fix. That last result proves
 *     the C4 residual gap is now closed: once handleEmbeddedCheckout()
 *     also participates in the claim protocol, all four paths converge.
 *
 * Fix (approved exactly as presented): the same atomic conditional UPDATE
 * claim as C1/C3/C4 (status: pending/abandoned -> 'claimed') before
 * ShopAPI::checkoutCart() is ever called. On claim rejection,
 * handleEmbeddedCheckout() performs the same bounded wait as C3/C4 (5 x
 * 100ms re-reads); if it observes a winner's order_id it returns this
 * method's OWN existing success shape (['ok' => true, 'order_id' => N]) —
 * the exact shape already used for "already converted to an order" — and
 * never a redirect (this method has no header()/exit() anywhere in it,
 * unlike the three top-level entry scripts). If the window expires it
 * returns this method's OWN existing error shape
 * (['ok' => false, 'error' => '...']) and never reaches checkoutCart(). A
 * checkout failure after a successful claim releases it back to 'pending'.
 * Because this is a class method (not a standalone script), the claim/
 * release/wait logic is implemented as private methods
 * (embeddedReleaseClaim(), embeddedWaitForCompletion()) rather than bare
 * functions copied from the other three files. No shared helper was
 * introduced across the four sites, per the approved scope — each of the
 * four implementations remains independently auditable.
 *
 * The $row['shop_sid'] !== $sid ownership check is untouched by this fix
 * (it stays exactly where it was, before any claim logic) — it uses a
 * plain strict-string comparison rather than success.php/return.php's
 * hash_equals(), a pre-existing difference unrelated to this TOCTOU fix
 * and explicitly out of scope here.
 *
 * Live reproduction through the real page is not possible: `shop` is
 * archived and not present under plugins/, and handleEmbeddedCheckout()
 * is only ever invoked via the archived shop's own checkout.php (same
 * reachability caveat as C1/C3/C4/H6). What IS established live, with
 * genuinely concurrent OS processes racing the real check-then-act /
 * claim-then-wait SQL shapes against a real stripepayment_sessions row, is
 * this test suite's own doing (see embedded-claim-probe.php) — the
 * specific consequence (a duplicate real shop_orders row) is established
 * by reading ShopAPI's archived source, not executed live.
 *
 * Scope: StripePayment::handleEmbeddedCheckout() only. return.php,
 * success.php, webhook.php, and archived shop code are NOT touched here.
 */

declare(strict_types=1);

/** Same defensive guard as the other three Stripe TOCTOU test files —
 *  duplicated rather than shared, since this file must not depend on load
 *  order (tests/integration/run.php globs files alphabetically). */
function secrt_ensure_columns(): void
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
secrt_ensure_columns();

function secrt_seed(string $status = 'pending', ?int $orderId = null): int
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

function secrt_probe_async(string $fixture, string $mode, int $rowId, int $fakeOrderId, string $fourth = '0'): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture) . ' '
         . escapeshellarg($mode) . ' ' . escapeshellarg((string) $rowId) . ' ' . escapeshellarg((string) $fakeOrderId)
         . ' ' . escapeshellarg($fourth);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    return ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

function secrt_embedded_async(string $mode, int $rowId, int $fakeOrderId, string $fourth = '0'): array
{
    return secrt_probe_async('embedded-claim-probe.php', $mode, $rowId, $fakeOrderId, $fourth);
}

function secrt_webhook_async(int $rowId, int $fakeOrderId, string $fourth = '0'): array
{
    // webhook.php's actual shipped (post-C1) shape, from C1's own fixture —
    // reused here read-only (invoked as a separate OS process, no code
    // shared) purely to race handleEmbeddedCheckout() against it.
    return secrt_probe_async('stripe-claim-probe.php', 'claim', $rowId, $fakeOrderId, $fourth);
}

function secrt_return_async(int $rowId, int $fakeOrderId, string $fourth = '0'): array
{
    // return.php's actual shipped (post-C4) shape, from C4's own fixture —
    // reused here read-only, same rationale as above.
    return secrt_probe_async('return-claim-probe.php', 'return', $rowId, $fakeOrderId, $fourth);
}

function secrt_probe_wait(array $handle): string
{
    $out = stream_get_contents($handle['stdout']);
    fclose($handle['stdout']);
    fclose($handle['stderr']);
    proc_close($handle['proc']);
    return trim((string) $out);
}

function secrt_clean(int $rowId): void
{
    Database::query('DELETE FROM stripepayment_sessions WHERE id = ?', [$rowId]);
}

unit('C5 vulnerable baseline: two concurrent completions of handleEmbeddedCheckout()\'s PRE-FIX pattern both proceed (duplicate order creation)', function (): void {
    $rowId = secrt_seed('pending');
    try {
        $a = secrt_embedded_async('old', $rowId, 6601);
        $b = secrt_embedded_async('old', $rowId, 6602);
        $results = [secrt_probe_wait($a), secrt_probe_wait($b)];
        sort($results);
        assert_eq(['PROCEEDED', 'PROCEEDED'], $results, 'the old, unguarded handleEmbeddedCheckout() pattern must let BOTH concurrent callers pass the empty-check and proceed — this is exactly the defect C5 closes');
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 vulnerable baseline: handleEmbeddedCheckout()\'s PRE-FIX pattern racing webhook.php\'s shipped C1 claim — the claim gives it no protection', function (): void {
    $rowId = secrt_seed('pending');
    try {
        $a = secrt_embedded_async('old', $rowId, 6701);
        $b = secrt_webhook_async($rowId, 6702);
        $results = [secrt_probe_wait($a), secrt_probe_wait($b)];
        sort($results);
        assert_eq(['PROCEEDED', 'PROCEEDED'], $results, 'handleEmbeddedCheckout() (pre-fix) selects but never reads status, so it must proceed even while webhook.php holds/wins the claim');
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 vulnerable baseline: handleEmbeddedCheckout()\'s PRE-FIX pattern racing return.php\'s shipped C4 fix — confirms the exact residual gap C4 reported', function (): void {
    $rowId = secrt_seed('pending');
    try {
        $embedded = secrt_probe_wait(secrt_embedded_async('old', $rowId, 6801));
        $return   = secrt_probe_wait(secrt_return_async($rowId, 6802));
        assert_eq('PROCEEDED', $embedded, 'pre-fix handleEmbeddedCheckout() must still proceed');
        assert_true(str_starts_with($return, 'PROCEEDED:') || str_starts_with($return, 'WINNER_OBSERVED:'), "return.php's fix must reach some completion outcome: $return");
        // The defect: BOTH independently create an order (return.php's own
        // claim succeeds since nothing blocks it; handleEmbeddedCheckout(),
        // pre-fix, never reads status and proceeds too) — this is C4's
        // "known residual gap" reproduced from the embedded side.
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 fix: two concurrent handleEmbeddedCheckout() completions result in exactly one claimant, the other observes the winner', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $rowId = secrt_seed('pending');
        try {
            $a = secrt_embedded_async('embedded', $rowId, 6811 + $i * 10);
            $b = secrt_embedded_async('embedded', $rowId, 6812 + $i * 10);
            $results = [secrt_probe_wait($a), secrt_probe_wait($b)];

            $proceeded = array_values(array_filter($results, fn($r) => str_starts_with($r, 'OK_ORDER:')));
            $observed  = array_values(array_filter($results, fn($r) => str_starts_with($r, 'OK_OBSERVED:')));

            assert_eq(1, count($proceeded), "iteration $i: exactly one concurrent claimant must actually proceed to checkout: " . implode(',', $results));
            assert_eq(1, count($observed), "iteration $i: the other must observe the winner rather than fail: " . implode(',', $results));

            $winnerId   = explode(':', $proceeded[0])[1];
            $observedId = explode(':', $observed[0])[1];
            assert_eq($winnerId, $observedId, "iteration $i: the loser must observe the SAME order id the winner created");

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq((int) $winnerId, (int) $row['order_id'], "iteration $i: the winner's order_id must be the one recorded");
            assert_eq('completed', $row['status'], "iteration $i: the row must end up completed, not stuck at claimed");
        } finally {
            secrt_clean($rowId);
        }
    }
});

unit('C5 fix: handleEmbeddedCheckout() vs webhook.php\'s shipped claim on the same embedded-flow row → exactly one can claim, the other observes or is skipped', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $rowId = secrt_seed('pending');
        try {
            $embeddedOut = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 6821 + $i * 10));
            $webhookOut  = secrt_probe_wait(secrt_webhook_async($rowId, 6822 + $i * 10));

            $webhookWon  = $webhookOut === 'PROCEEDED';
            $embeddedWon = str_starts_with($embeddedOut, 'OK_ORDER:');
            assert_true($webhookWon xor $embeddedWon, "iteration $i: exactly one side must win the claim: embedded=$embeddedOut webhook=$webhookOut");

            if ($webhookWon) {
                assert_eq('OK_OBSERVED:' . (6822 + $i * 10), $embeddedOut, "iteration $i: when webhook.php's claim wins, handleEmbeddedCheckout() must observe its order_id rather than duplicate it: $embeddedOut");
            } else {
                assert_eq('SKIPPED', $webhookOut, "iteration $i: when handleEmbeddedCheckout()'s claim wins, the bare webhook-shaped claimant must be skipped: $webhookOut");
            }

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq('completed', $row['status'], "iteration $i: exactly one order must be recorded, and the row must end completed either way");
        } finally {
            secrt_clean($rowId);
        }
    }
});

unit('C5 FINAL VERIFICATION: handleEmbeddedCheckout() vs return.php\'s shipped C4 fix — the C4 residual gap is now closed', function (): void {
    // This is the key final-verification test: proves that, with C5
    // shipped, all four paths converge on the SAME claim state. Uses
    // return.php's real, unmodified (since C4) shape directly, not a
    // simulation of it.
    for ($i = 0; $i < 4; $i++) {
        $rowId = secrt_seed('pending');
        try {
            $embeddedOut = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 6831 + $i * 10));
            $returnOut   = secrt_probe_wait(secrt_return_async($rowId, 6832 + $i * 10));

            $embeddedWon = str_starts_with($embeddedOut, 'OK_ORDER:');
            $returnWon   = str_starts_with($returnOut, 'PROCEEDED:');
            assert_true($embeddedWon xor $returnWon, "iteration $i: exactly one side must win the claim: embedded=$embeddedOut return=$returnOut (BEFORE C5 this always showed both proceeding — the residual gap)");

            if ($embeddedWon) {
                $winnerId = explode(':', $embeddedOut)[1];
                assert_eq('WINNER_OBSERVED:' . $winnerId, $returnOut, "iteration $i: when handleEmbeddedCheckout() wins, return.php must observe its order_id: $returnOut");
            } else {
                $winnerId = explode(':', $returnOut)[1];
                assert_eq('OK_OBSERVED:' . $winnerId, $embeddedOut, "iteration $i: when return.php wins, handleEmbeddedCheckout() must observe its order_id: $embeddedOut");
            }

            $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
            assert_eq('completed', $row['status'], "iteration $i: exactly one order must be recorded across the two real, shipped implementations");
        } finally {
            secrt_clean($rowId);
        }
    }
});

unit('C5 fix: the losing handleEmbeddedCheckout() call correctly handles an active claimed row (webhook still mid-flight)', function (): void {
    $rowId = secrt_seed('claimed', null);
    try {
        $embedded = secrt_embedded_async('embedded', $rowId, 0);
        $finisher = secrt_probe_async('embedded-claim-probe.php', 'finisher', $rowId, 10001, '200'); // lands ~200ms in, inside the 500ms window

        $embeddedOut = secrt_probe_wait($embedded);
        $finisherOut = secrt_probe_wait($finisher);

        assert_eq('FINISHED:10001', $finisherOut);
        assert_eq('OK_OBSERVED:10001', $embeddedOut, 'handleEmbeddedCheckout() must observe the order_id once the actively-processing path finishes, not fail safe prematurely');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(10001, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 fix: if the claim remains unavailable for the full bounded window, handleEmbeddedCheckout() fails safe without ever reaching checkout', function (): void {
    $rowId = secrt_seed('claimed', null);
    try {
        $start = microtime(true);
        $result = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 0));
        $elapsed = microtime(true) - $start;

        assert_eq('ERR_NO_ORDER', $result, 'with no order_id ever landing, handleEmbeddedCheckout() must return its existing error shape rather than proceed to checkout or hang');
        assert_true($elapsed >= 0.5 && $elapsed < 1.5, 'the fail-safe path must take roughly the full bounded window (5 x 100ms), not exit early or hang: took ' . $elapsed . 's');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_null($row['order_id'], 'no order_id must be fabricated on a failed-safe outcome');
        assert_eq('claimed', $row['status'], 'handleEmbeddedCheckout() must not touch a row it never itself claimed — the other (unresolved) claimant still owns it');
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 fix: an already-completed row is treated as idempotent even if the claim/wait mechanism is reached', function (): void {
    $rowId = secrt_seed('completed', 555);
    try {
        $result = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 0));
        assert_eq('OK_OBSERVED:555', $result, 'a row that already has an order_id must be observed immediately, never re-claimed or re-processed');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(555, (int) $row['order_id'], 'the original order_id must be untouched');
        assert_eq('completed', $row['status'], 'the original status must be untouched');
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 fix: a checkout failure releases the claim back to pending', function (): void {
    $rowId = secrt_seed('pending');
    try {
        $result = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 9951, '1'));
        assert_eq('RELEASED', $result);

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_null($row['order_id'], 'order_id must remain unset after a released claim');
        assert_eq('pending', $row['status'], 'status must be restored to pending, not left at claimed');
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 fix: a subsequent retry can successfully reclaim and complete a row released after a prior failure', function (): void {
    $rowId = secrt_seed('pending');
    try {
        $failed = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 9961, '1'));
        assert_eq('RELEASED', $failed, 'setup: the first attempt must fail and release');

        $retried = secrt_probe_wait(secrt_embedded_async('embedded', $rowId, 9962, '0'));
        assert_eq('OK_ORDER:9962', $retried, 'a retry after a released claim must be able to complete normally');

        $row = Database::row('SELECT order_id, status FROM stripepayment_sessions WHERE id = ?', [$rowId]);
        assert_eq(9962, (int) $row['order_id']);
        assert_eq('completed', $row['status']);
    } finally {
        secrt_clean($rowId);
    }
});

unit('C5 fix: StripePayment::handleEmbeddedCheckout() ships the atomic claim before ShopAPI::checkoutCart(), waits on rejection using private methods, releases on both failure paths, preserves the ownership check, and introduces no shared helper', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePayment.php');

    $fnPos = strpos($src, 'public function handleEmbeddedCheckout(string $sid, array $billing): array {');
    assert_true($fnPos !== false, 'handleEmbeddedCheckout() must still exist with its original signature');
    $fnEnd = strpos($src, "\n    }\n", $fnPos);
    $fn = substr($src, $fnPos, ($fnEnd !== false ? $fnEnd - $fnPos : 5000));

    $ownershipCheckPos = strpos($fn, "\$row['shop_sid'] !== \$sid");
    $orderIdCheckPos   = strpos($fn, "if (!empty(\$row['order_id'])) {");
    $claimPos          = strpos($fn, "'status' => 'claimed'");
    $waitCallPos       = strpos($fn, '$this->embeddedWaitForCompletion(');
    $checkoutCallPos   = strpos($fn, 'ShopAPI::checkoutCart($sid');
    $throwCatchPos     = strpos($fn, "catch (\\Throwable \$e) {\n            slate_log('Stripe embedded checkoutCart threw:");
    $notOkCheckPos     = strpos($fn, "if (empty(\$res['ok']) || empty(\$res['order_id'])) {");
    $finalWritePos     = strpos($fn, "'status'       => 'completed',");

    assert_true($ownershipCheckPos !== false, 'the $sid ownership check must still be present, unchanged');
    assert_true($orderIdCheckPos !== false, 'the existing already-processed check must still be present');
    assert_true($claimPos !== false, 'the atomic claim must be present');
    assert_true($waitCallPos !== false, 'the bounded wait-for-completion call must be present, via a private method ($this->...)');
    assert_true($checkoutCallPos !== false, 'the checkoutCart() call must still be present');
    assert_true($throwCatchPos !== false, 'the checkoutCart() throw-catch must be present');
    assert_true($notOkCheckPos !== false, 'the !ok result check must still be present');
    assert_true($finalWritePos !== false, 'the final completion write must still be present');

    assert_true($ownershipCheckPos < $orderIdCheckPos, 'the ownership check must remain BEFORE the idempotency/claim logic, unchanged in position');
    assert_true($orderIdCheckPos < $claimPos, 'the claim must come after the existing already-processed check (never re-claim a row already known to have an order)');
    assert_true($claimPos < $waitCallPos, 'the wait-for-completion call must be reachable only from the claim-rejected branch, after the claim attempt');
    assert_true($waitCallPos < $checkoutCallPos, 'the claim (and its rejection handling) must happen BEFORE checkoutCart() is ever called — that is the entire point');

    $throwBranch = substr($fn, $throwCatchPos, ($notOkCheckPos - $throwCatchPos));
    assert_true(str_contains($throwBranch, '$this->embeddedReleaseClaim('), 'the checkoutCart()-threw branch must release the claim via the private method');

    $notOkBranchEnd = strpos($fn, '$orderId = (int)$res', $notOkCheckPos);
    $notOkBranch = substr($fn, $notOkCheckPos, ($notOkBranchEnd !== false ? $notOkBranchEnd - $notOkCheckPos : 400));
    assert_true(str_contains($notOkBranch, '$this->embeddedReleaseClaim('), 'the !ok-result branch must release the claim via the private method');

    // No header()/exit() anywhere in this method — every path must return an array.
    assert_true(!str_contains($fn, 'header(') && !str_contains($fn, 'exit;') && !str_contains($fn, 'exit('), 'handleEmbeddedCheckout() must not introduce header()/exit() — every path must return an array, per the approved constraint');

    // The two private helper methods must exist as METHODS (not bare
    // functions) on the class, distinct from the other three files' names.
    assert_true(str_contains($src, 'private function embeddedReleaseClaim(int $rowId): void {'), 'embeddedReleaseClaim() must exist as a private class method');
    assert_true(str_contains($src, 'private function embeddedWaitForCompletion(int $rowId): ?array {'), 'embeddedWaitForCompletion() must exist as a private class method');

    // Scope guard: no shared helper was introduced (e.g. in StripePaymentAPI.php).
    $apiSrc = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePaymentAPI.php');
    assert_true(!str_contains($apiSrc, "'status' => 'claimed'"), 'no shared claim helper must have been introduced in StripePaymentAPI.php — each of the four sites stays independently auditable, per the approved scope');
});

unit('C5 scope: return.php, success.php, and webhook.php are unmodified by this finding', function (): void {
    // Lightweight scope guards — confirm C5 did not touch the other three
    // already-shipped files. Checks each still contains ITS OWN distinctly
    // named helper (not renamed/refactored toward a shared one).
    $returnSrc  = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/return.php');
    $successSrc = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/success.php');
    $webhookSrc = file_get_contents(dirname(__DIR__, 2) . '/plugins/stripe-payment/public/webhook.php');

    assert_true(str_contains($returnSrc, 'function returnReleaseClaim(int $rowId): void {'), 'return.php must still have its own independent releaseClaim helper, untouched by C5');
    assert_true(str_contains($successSrc, 'function successReleaseClaim(int $rowId): void {'), 'success.php must still have its own independent releaseClaim helper, untouched by C5');
    assert_true(str_contains($webhookSrc, 'function releaseClaim(int $rowId): void {'), 'webhook.php must still have its own independent releaseClaim helper, untouched by C5');
});
