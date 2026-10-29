<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — post dates in the page's language.
 *
 * Autoloader only (no database): Html::date() keeps the English "Jul 15, 2025" by default, the French pack sets the
 * format to "15 juillet 2025" with the core month names, and no Studio renderer formats a date in English by itself.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Render\Html;
use Slate\Services\I18n\I18n;

/** Noon UTC on 15 July 2025, so every timezone shows the same day. */
const SBLD_TS = 1752580800;

unit('studio local date: English by default, the language pack can change the format', function (): void {
    I18n::pinLocale(null);
    I18n::resetCache();
    assert_eq('Jul 15, 2025', Html::date(SBLD_TS), 'the English default is unchanged');

    $pack = include dirname(__DIR__, 2) . '/plugins/studio-builder/lang/fr.php';
    assert_eq('j F Y', $pack['studio_date_format'] ?? null, 'the French pack writes the day first, then the full month');
    assert_true(str_contains((string) ($pack['studio_read_full_story'] ?? ''), '%s'), 'the excerpt fallback keeps the title placeholder');

    I18n::pinLocale('fr');
    I18n::resetCache();
    try {
        assert_eq('15 juillet 2025', I18n::localDate($pack['studio_date_format'], SBLD_TS), 'French month names from the core pack');
    } finally {
        I18n::pinLocale(null);
        I18n::resetCache();
    }
});

unit('studio local date: no Studio code formats a visible date in English by itself', function (): void {
    $root = dirname(__DIR__, 2) . '/src/Module/StudioBuilder';
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match("/\\bdate\\('M j, Y'/", (string) file_get_contents($file->getPathname())) === 1) {
            $hits[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    assert_eq([], $hits, 'use Html::date()');
});
