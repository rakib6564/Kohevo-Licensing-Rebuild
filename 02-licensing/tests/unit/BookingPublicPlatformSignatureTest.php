<?php
/**
 * Regression test for the booking public widget's footer.
 *
 * It previously read Database::setting('site_name') and printed "Powered by
 * <that value>" — showing the TENANT's own business name (nonsensical as an
 * attribution line), and only "looking like" platform branding for a tenant
 * that never set one (site_name falls back to the literal 'Kohevo'). Fixed
 * to route through PlatformSignature, matching every other "Powered by
 * Kohevo" surface (admin/customer shell, login, error pages, email) and
 * picking up the mark image + Phase 6 white-label suppression for free.
 * Source-based check: the widget's multi-step, query-param-driven routing
 * makes a full HTTP-level probe disproportionate for a one-line fix already
 * verified end-to-end in the browser.
 */

declare(strict_types=1);

unit('booking public router.php: the non-embedded footer renders the platform signature exactly once and no longer reads the tenant\'s own site_name', function () {
    $src = file_get_contents(__DIR__ . '/../../plugins/booking/public/router.php');
    assert_eq(1, substr_count($src, '\Slate\Services\Content\PlatformSignature::render'), 'expected exactly one PlatformSignature::render call');
    assert_true(str_contains($src, 'PlatformSignature::MODE_SIGNATURE'));

    // Isolate the footer's actual CODE (not bookpub_layout_start()'s own,
    // unrelated $siteName use for the page <title> earlier in the file, and
    // not this block's own explanatory comment, which legitimately mentions
    // the old pattern in prose) and confirm the live call is gone.
    $footerPos = strpos($src, "echo '<footer class=\"book-footer");
    assert_true($footerPos !== false, 'footer echo statement not found');
    $footerCode = substr($src, $footerPos, 250);
    assert_false(str_contains($footerCode, 'site_name'), 'the footer\'s own echo statement must no longer read the tenant\'s own site_name');
});

unit('booking public router.php: the embedded (?embed=1) path is untouched — still no footer inside an iframe', function () {
    $src = file_get_contents(__DIR__ . '/../../plugins/booking/public/router.php');
    $embedGuardPos = strpos($src, "if (\$embed && bookpub_is_fragment())");
    $footerPos     = strpos($src, "if (!\$embed) {");
    assert_true($embedGuardPos !== false && $footerPos !== false, 'expected markers not found');
    assert_true($embedGuardPos < $footerPos, 'the fragment/embed early-return must still precede the footer block');
});
