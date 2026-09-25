<?php
/**
 * Phase 1D F1 — Forms public "Save PDF" / receipt-summary gate crashed
 * instead of failing closed when APP_SECRET is unconfigured.
 *
 * plugins/forms/public/router.php had two call sites —
 * `hash_equals(forms_public_pdf_token($ref), $tok)` — where
 * forms_public_pdf_token() can return null (CORE-1: no secret configured →
 * nothing can be signed or verified). Passing that null into hash_equals()'s
 * non-nullable `string $known_string` parameter throws an uncaught TypeError
 * on PHP 8.1+ (verified directly against the real, unmodified helpers.php in
 * this environment's PHP 8.5), producing a 500 rather than the safe "not
 * available" response the surrounding code clearly intended.
 *
 * This REFINES the original finding's severity rather than confirming it
 * as originally written: on this codebase's actual target PHP versions
 * (8.3-8.5), the unconfigured-secret case was never a silent
 * information-disclosure bypass (hash_equals() never got the chance to
 * wrongly return true — it never returned at all) — it was a
 * crash/robustness bug. Still worth fixing, but the fix is the same
 * either way: line 75 in this exact file already established the correct,
 * safe convention (`slate_sign_equals('forms-pdf', $ref, $tok, 24)`), and
 * the two other call sites just weren't using it.
 */

declare(strict_types=1);

function fptrt_probe(string $mode): array
{
    $script = __DIR__ . '/fixtures/forms-pdf-token-probe.php';
    $cmd    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($mode);
    $raw    = shell_exec($cmd . ' 2>/dev/null');
    $json   = json_decode((string) $raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("probe '$mode' produced no JSON: " . var_export($raw, true));
    }
    return $json;
}

unit('the OLD router.php pattern really does crash when APP_SECRET is unconfigured (vulnerable-baseline confirmation)', function (): void {
    $p = fptrt_probe('missing');
    assert_true($p['old_pattern_threw'], 'hash_equals(forms_public_pdf_token($ref), $tok) must throw when the token function returns null');
    assert_eq('TypeError', $p['old_pattern_exception'] ?? null, 'the failure must be the specific TypeError this finding is about, not something else');
});

unit('the NEW router.php pattern (slate_sign_equals) fails closed instead of crashing, with or without a configured secret', function (): void {
    $missing = fptrt_probe('missing');
    assert_false($missing['new_pattern_threw'], 'slate_sign_equals() must never throw');
    assert_false($missing['new_pattern_result'], 'with no secret configured, the token can never be verified — must resolve to false, not true');

    $set = fptrt_probe('set');
    assert_false($set['new_pattern_threw'], 'slate_sign_equals() must never throw even with a secret configured');
    assert_false($set['new_pattern_result'], 'an attacker-supplied garbage token must never verify, even with a real secret configured');
});

unit('plugins/forms/public/router.php: both fixed call sites use slate_sign_equals(), matching the file\'s own established convention (fix present in the shipped file)', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/forms/public/router.php');

    $occurrences = substr_count($src, "slate_sign_equals('forms-pdf', \$ref, \$tok, 24)");
    assert_eq(3, $occurrences, 'all three ref+token checks in this file (the pdf action, the thanks-page PDF offer, and the receipt summary gate) must use the same safe convention');

    assert_false(
        str_contains($src, "hash_equals(forms_public_pdf_token(\$ref), \$tok)"),
        'the raw, crash-prone hash_equals(forms_public_pdf_token(...), ...) pattern must be fully gone'
    );

    // The one place forms_public_pdf_token()'s return value is still used
    // directly must be a null-safe string cast, not fed raw into anything
    // that requires a non-nullable string.
    assert_true(
        str_contains($src, '$tok = (string) forms_public_pdf_token($ref);'),
        'the session-fallback reassignment must cast to string so a null token can never reach a non-nullable string parameter downstream'
    );
});
