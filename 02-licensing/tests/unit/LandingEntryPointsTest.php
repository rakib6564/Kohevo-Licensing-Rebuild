<?php
/**
 * Regression coverage for the redesigned public landing page (the "choose
 * where to go" screen at the site root). Rebuilt as a two-panel split
 * matching admin/login.php and customer/login.php: a dark hero panel and a
 * light entry panel listing tiles for Book now, Customer login, Admin
 * login, then featured forms — each on by default, each independently
 * toggleable from Settings → Landing.
 *
 * Source-based: rendering this page end-to-end needs a full tenant/plugin
 * fixture (booking plugin active, a published form, etc.) that duplicates
 * what tests/integration already covers for similar public pages; the
 * structural contract asserted here — tile order, default-on toggles, no
 * duplicate logo — is fully verifiable from source.
 */

declare(strict_types=1);

unit('landing page: the customer and admin login tiles default to visible (unset setting reads as shown, only an explicit \'0\' hides one)', function () {
    $src = file_get_contents(__DIR__ . '/../../includes/landing.php');
    assert_true(str_contains($src, "Database::setting('landing_show_customer_login') !== '0'"), 'customer login tile must default on');
    assert_true(str_contains($src, "Database::setting('landing_show_admin_login') !== '0'"), 'admin login tile must default on');
    assert_true(str_contains($src, "Database::setting('landing_booking_enabled') !== '0'"), 'booking tile must default on (changed from the old opt-in default)');
});

unit('landing page: tiles render in a fixed order — booking, customer login, admin login, then featured forms', function () {
    $src = file_get_contents(__DIR__ . '/../../includes/landing.php');
    $bookPos     = strpos($src, "landing_booking_enabled') !== '0'");
    $customerPos = strpos($src, "landing_show_customer_login') !== '0'");
    $adminPos    = strpos($src, "landing_show_admin_login') !== '0'");
    $formsPos    = strpos($src, "Database::setting('landing_forms_json')");
    assert_true($bookPos !== false && $customerPos !== false && $adminPos !== false && $formsPos !== false, 'expected markers not found');
    assert_true($bookPos < $customerPos, 'booking tile must be built before the customer login tile');
    assert_true($customerPos < $adminPos, 'customer login tile must be built before the admin login tile');
    assert_true($adminPos < $formsPos, 'admin login tile must be built before featured forms are appended');
});

unit('landing page: customer and admin tiles link to the real login routes', function () {
    $src = file_get_contents(__DIR__ . '/../../includes/landing.php');
    assert_true(str_contains($src, "SLATE_URL . '/customer/login.php'"), 'customer login tile must link to /customer/login.php');
    assert_true(str_contains($src, "SLATE_URL . '/admin/login.php'"), 'admin login tile must link to /admin/login.php');
});

unit('landing page: the tenant logo renders only in the entry panel, not repeated on the hero panel', function () {
    $src = file_get_contents(__DIR__ . '/../../includes/landing.php');
    $heroStart = strpos($src, '<aside class="hero"');
    $heroEnd   = strpos($src, '</aside>');
    $panelStart = strpos($src, '<main class="panel">');
    assert_true($heroStart !== false && $heroEnd !== false && $panelStart !== false, 'expected markers not found');
    $heroMarkup = substr($src, $heroStart, $heroEnd - $heroStart);
    assert_false(str_contains($heroMarkup, '$logoUrl') && str_contains($heroMarkup, '<img'), 'the hero panel must not render the tenant logo image');
    assert_eq(1, substr_count($src, '<img src="<?= $e($logoUrl) ?>"'), 'the tenant logo <img> must appear exactly once, in the entry panel');
    assert_true(strpos($src, '<img src="<?= $e($logoUrl) ?>"') > $panelStart, 'the logo <img> must be inside the entry panel, not the hero panel');
});

unit('landing page: Settings -> Landing persists the two new entry-point toggles', function () {
    $src = file_get_contents(__DIR__ . '/../../admin/settings.php');
    assert_true(str_contains($src, "Database::setSetting('landing_show_customer_login', isset(\$_POST['landing_show_customer_login']) ? '1' : '0')"), 'customer login toggle must be saved');
    assert_true(str_contains($src, "Database::setSetting('landing_show_admin_login',    isset(\$_POST['landing_show_admin_login'])    ? '1' : '0')"), 'admin login toggle must be saved');
});

unit('landing page: Settings -> Landing reads the booking and entry-point toggles as on by default', function () {
    $src = file_get_contents(__DIR__ . '/../../admin/settings.php');
    assert_true(str_contains($src, "'enabled' => Database::setting('landing_booking_enabled') !== '0',"), 'booking checkbox must read as checked by default');
    assert_true(str_contains($src, "\$landingShowCustomerLogin = Database::setting('landing_show_customer_login') !== '0';"), 'customer login checkbox must read as checked by default');
    assert_true(str_contains($src, "\$landingShowAdminLogin    = Database::setting('landing_show_admin_login')    !== '0';"), 'admin login checkbox must read as checked by default');
});

unit('landing page: the eyebrow/intro fallbacks are generic, not the old vessel-survey-specific copy, and the eyebrow hides entirely when unset', function () {
    $landingSrc  = file_get_contents(__DIR__ . '/../../includes/landing.php');
    $settingsSrc = file_get_contents(__DIR__ . '/../../admin/settings.php');
    foreach ([$landingSrc, $settingsSrc] as $src) {
        assert_false(str_contains($src, 'Vessel Survey Orders'), 'the old business-specific eyebrow default must be gone');
        assert_false(str_contains($src, 'Please choose either Powerboat or Sailboat'), 'the old business-specific intro default must be gone');
    }
    assert_true(str_contains($landingSrc, "\$eyebrow = (string)Database::setting('landing_eyebrow');"), 'the eyebrow must have no fabricated fallback text — blank when unset');
    assert_true(str_contains($landingSrc, "if (\$eyebrow !== ''): ?><span class=\"hero-eyebrow\">"), 'the eyebrow element must not render at all when blank');
    assert_true(str_contains($landingSrc, "'Choose an option below to get started.'"), 'the intro fallback must be a generic call to action');
});
