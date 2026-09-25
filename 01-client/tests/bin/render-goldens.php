<?php
/**
 * Regenerate the golden HTML for every document fixture.
 *
 * Run deliberately, and read the diff before committing: a change here is a
 * change to what visitors see. That is the whole value of checking the output
 * in rather than asserting loosely on fragments.
 *
 *   php tests/bin/render-goldens.php
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/config.php';

/** Remove this install's base prefix so a golden is portable across installs. */
function slate_strip_install_base(string $html): string {
    $base = rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/');
    if ($base === '') return $html;
    return str_replace(['="' . $base . '/', "('" . $base . '/'], ['="/', "('/"], $html);
}

$dir = dirname(__DIR__) . '/fixtures/documents';
$out = $dir . '/expected';
@mkdir($out, 0755, true);

// Goldens are stored BASE-NEUTRAL, as a root install renders them.
//
// They were previously written with whatever base the generating checkout used,
// which made them install-coupled: goldens baked with '/slate' failed in CI,
// which runs at the root. A checked-in expectation must not depend on where the
// generating machine happened to serve from.
//
// The install's base is stripped here and re-stripped from the actual output at
// comparison time, so the comparison is about markup. That the base is APPLIED
// at all is asserted separately, against the live configuration — that assertion
// is what protects the sub-path install, not these files.
file_put_contents($out . '/_context.json', json_encode([
    'base' => '',
], JSON_PRETTY_PRINT) . "\n");

foreach (glob($dir . '/*.json') ?: [] as $path) {
    $name = basename($path, '.json');
    $html = slate_strip_install_base(ContentCoreBridge::render((string) file_get_contents($path)));
    file_put_contents($out . '/' . $name . '.html', $html);
    printf("  %-18s %6d bytes\n", $name, strlen($html));
}

// ── Document envelope ─────────────────────────────────────
//
// The goldens above stop at PageRenderer, so they never see the <head> and never
// observe the token block. These render the full envelope under the contexts in
// envelope_cases.php, pinning the themed/unthemed and color-scheme behaviour that
// a body-only expectation is blind to.
require $dir . '/envelope_cases.php';

$envOut = $out . '/envelope';
@mkdir($envOut, 0755, true);

$envSource = slate_envelope_source();
foreach (slate_envelope_cases() as $case => $ctx) {
    $html = slate_strip_install_base(slate_render_envelope($envSource, $ctx));
    file_put_contents($envOut . '/' . $case . '.html', $html);
    printf("  envelope/%-9s %6d bytes\n", $case, strlen($html));
}

// ── Site document ─────────────────────────────────────────
//
// The envelope lane above cannot see a token CONSUMER change: DocumentTemplate
// emits the token block but inlines none of the stylesheets that read it. This
// lane renders through Theme::renderPage, which inlines Branding::cssVars() and
// public.css, so all 103 var(--cb-*) consumers are in the bytes — which is what
// makes it able to fail when Phase E step 2 renames one.
require $dir . '/site_cases.php';

$siteOut = $out . '/site';
@mkdir($siteOut, 0755, true);

$html = slate_strip_install_base(slate_render_site_document(slate_site_source()));
file_put_contents($siteOut . '/branded.html', $html);
printf("  site/%-13s %6d bytes\n", 'branded', strlen($html));

// ── SBK document ──────────────────────────────────────────
//
// The gate for sb.css. Loaded require-don't-activate: activating SBK would move
// every other golden above, which is the failure 2b was built to avoid.
require $dir . '/sbk_cases.php';

$sbkHtml = slate_render_sbk_document(slate_sbk_source());
if ($sbkHtml !== '') {
    $sbkOut = $out . '/sbk';
    @mkdir($sbkOut, 0755, true);
    $sbkHtml = slate_strip_install_base($sbkHtml);
    file_put_contents($sbkOut . '/branded.html', $sbkHtml);
    printf("  sbk/%-14s %6d bytes\n", 'branded', strlen($sbkHtml));
} else {
    echo "  sbk/branded        (skipped: SBK sources unavailable)\n";
}
