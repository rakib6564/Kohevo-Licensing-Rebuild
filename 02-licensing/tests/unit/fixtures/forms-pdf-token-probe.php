<?php
/**
 * Probe: the exact before/after PDF-token comparison from
 * plugins/forms/public/router.php, under each APP_SECRET state.
 *
 * APP_SECRET is a constant, so its states can't be exercised in one process
 * (see app-secret-probe.php, same technique). router.php itself can't be
 * required directly to isolate forms_public_pdf_token() — it's a full page
 * script that dispatches on $_GET['_route_path'] the moment it loads, not a
 * function library — so this probe inlines the exact same expression
 * forms_public_pdf_token() evaluates (slate_sign('forms-pdf', $ref, 24)) using
 * the real, unmodified helpers.php, rather than a stand-in for it.
 *
 * Usage: php forms-pdf-token-probe.php missing|set
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$mode = $argv[1] ?? '';
switch ($mode) {
    case 'missing': break; // never define it
    case 'set':     define('APP_SECRET', str_repeat('a', 64)); break;
    default:
        fwrite(STDERR, "usage: forms-pdf-token-probe.php missing|set\n");
        exit(2);
}

require dirname(__DIR__, 3) . '/includes/helpers.php';

$ref = 'probe-ref-' . bin2hex(random_bytes(4));
$tok = 'attacker-supplied-garbage-token';

$out = ['mode' => $mode];

// The OLD router.php pattern: hash_equals(forms_public_pdf_token($ref), $tok).
// forms_public_pdf_token()'s entire body is `slate_sign('forms-pdf', $ref, 24)`
// — inlined here since the real function lives inside router.php's page script.
try {
    hash_equals(slate_sign('forms-pdf', $ref, 24), $tok);
    $out['old_pattern_threw'] = false;
} catch (\Throwable $e) {
    $out['old_pattern_threw'] = true;
    $out['old_pattern_exception'] = get_class($e);
}

// The NEW router.php pattern: slate_sign_equals('forms-pdf', $ref, $tok, 24).
try {
    $out['new_pattern_result'] = slate_sign_equals('forms-pdf', $ref, $tok, 24);
    $out['new_pattern_threw'] = false;
} catch (\Throwable $e) {
    $out['new_pattern_threw'] = true;
    $out['new_pattern_exception'] = get_class($e);
}

echo json_encode($out), "\n";
