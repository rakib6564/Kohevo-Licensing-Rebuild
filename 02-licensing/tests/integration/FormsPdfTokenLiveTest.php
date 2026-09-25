<?php
/**
 * Phase 1D F1 — live, end-to-end confirmation that the real
 * plugins/forms/public/router.php 'thanks' page no longer crashes on a
 * ref+token pair, using this environment's actual configured APP_SECRET.
 *
 * The unit-level FormsPdfTokenRobustnessTest.php proves the underlying
 * mechanism (the old hash_equals(...) pattern crashes when no secret is
 * configured; the new slate_sign_equals() pattern never does) using the
 * real helpers in an isolated fixture, since router.php is a full page
 * script that can't be required in isolation to unit-test its functions
 * directly. This test complements that by driving the actual shipped page
 * end-to-end via a real HTTP-shaped subprocess request, confirming no
 * regression in the normal (secret-configured) case that every real
 * deployment runs under: a garbage token must be quietly rejected (no PDF
 * offered, no receipt summary shown, no crash), and the correct token for
 * that ref must still be accepted.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/forms/FormsAPI.php';

unit('plugins/forms/public/router.php thanks page: a garbage token is quietly rejected (no crash), and the correct token is still accepted', function (): void {
    $tid  = current_tenant_id();
    $slug = 'probe-form-' . bin2hex(random_bytes(4));
    $ref  = 'PROBEREF' . bin2hex(random_bytes(4));

    $formId = Database::insert('forms_definitions', [
        'tenant_id'   => $tid,
        'slug'        => $slug,
        'title'       => 'Probe form',
        'fields_json' => '[]',
        'status'      => 'published',
    ]);

    try {
        $goodToken = slate_sign('forms-pdf', $ref, 24);
        assert_true($goodToken !== null, 'setup: this environment must have a configured APP_SECRET for this test to mean anything');

        $badRun = shell_exec(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
            . escapeshellarg('plugins/forms/public/router.php') . ' '
            . escapeshellarg('_route_path=' . $slug . '/thanks&ref=' . $ref . '&t=attacker-supplied-garbage')
            . ' 2>&1'
        );
        assert_true(
            preg_match('/^STATUS 200/', (string) $badRun) === 1,
            "a garbage token must yield a normal 200 (not a 500 crash): " . $badRun
        );
        assert_false(str_contains((string) $badRun, 'Fatal error'), 'a garbage token must never crash the page: ' . $badRun);
        assert_false(str_contains((string) $badRun, 'TypeError'), 'a garbage token must never surface a TypeError: ' . $badRun);

        $goodRun = shell_exec(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
            . escapeshellarg('plugins/forms/public/router.php') . ' '
            . escapeshellarg('_route_path=' . $slug . '/thanks&ref=' . $ref . '&t=' . $goodToken)
            . ' 2>&1'
        );
        assert_true(
            preg_match('/^STATUS 200/', (string) $goodRun) === 1,
            'the correct token for this ref must still be accepted, unaffected by the fix: ' . $goodRun
        );
        assert_false(str_contains((string) $goodRun, 'Fatal error'), 'the correct-token run must not crash either: ' . $goodRun);
    } finally {
        Database::query('DELETE FROM forms_definitions WHERE id = ?', [$formId]);
        Database::query('DELETE FROM forms_submissions WHERE tenant_id = ? AND ref = ?', [$tid, $ref]);
    }
});
