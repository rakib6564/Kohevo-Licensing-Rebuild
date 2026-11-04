<?php
/**
 * Studio public — class catalog. Scope: public/router.php (no login required).
 *
 * A dispatcher, not a view. The catalog is the one page every parent sees, and
 * there is more than one right way to show it: a day picker keeps a busy day
 * short, a week grid puts every class and price on one screen with nothing to
 * tap. Studios disagree about which they want, so the choice is a setting.
 *
 * Templates live in views/catalog/ and the slug IS the filename, so adding a
 * third is a file plus a row in StudioAPI::catalogTemplates(). The fallback to
 * list.php matters: a setting can name a template that a later deploy removed,
 * and a parent must still get a catalog rather than a blank page.
 */
if (!defined('SLATE_ROOT')) { exit; }

// ?tpl= previews a layout without switching the studio over to it, so an
// owner can see the real catalog in both before choosing. Only ever a slug
// the registry already knows, so it can never name a file of the caller's
// choosing — and since the two templates differ only in layout, there is
// nothing here for a visitor to reach that the default did not already show.
$studioCatalogTemplate = StudioAPI::catalogTemplate();
$studioCatalogPreview  = trim((string) ($_GET['tpl'] ?? ''));

if ($studioCatalogPreview !== '' && isset(StudioAPI::catalogTemplates()[$studioCatalogPreview])) {
    $studioCatalogTemplate = $studioCatalogPreview;
}

$studioCatalogFile = __DIR__ . '/catalog/' . $studioCatalogTemplate . '.php';

// A setting can name a template a later deploy removed. A parent must still
// get a catalog rather than a blank page.
if (!is_file($studioCatalogFile)) {
    $studioCatalogFile = __DIR__ . '/catalog/list.php';
}

require $studioCatalogFile;
