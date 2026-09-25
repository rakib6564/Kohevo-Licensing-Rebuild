<?php
/**
 * Phase 1C H5 — stored XSS via translated-text substitution.
 *
 * MLT_Tokenizer::translateTextNode() spliced $onText()'s return value —
 * ultimately a translator-controlled `translated_text` DB value, resolved by
 * MLT_Replacer::replace() and applied to every page's HTML on every request
 * (MultilangTranslate::obCallback() wraps the whole response in an
 * ob_start() callback whenever the active locale isn't the default, not
 * just customer-facing pages) — directly into a text-node position with no
 * HTML escaping at all. Anyone holding only mlt.manage could store e.g.
 * `<img src=x onerror=...>` as a "translation" and have it execute in every
 * visitor's, including an admin's, browser the next time that locale was
 * active.
 *
 * The fix applies e() — the project's one HTML-escaping helper, already
 * used for this same value in the translation grid's own <textarea> at
 * admin/index.php:166 — at the exact point the value is spliced into
 * text-node HTML. $core (used when there is no replacement) is left
 * untouched: it is not new output being constructed, it is markup already
 * present in the page passed in. translateAttrs()'s separate escaping
 * (`&quot;`, appropriate for an attribute-value context) is untouched —
 * a different code path, already sound, not part of this finding.
 *
 * Required by this fix and NOT broken by it: legitimate translations
 * containing literal `<`, `>`, `&` must still render as that literal text
 * (not corrupted, not double-escaped), and the ordinary mlt.manage save ->
 * publish -> replace pipeline must keep working for normal content.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/Tokenizer.php';
require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/MultilangTranslate.php';
require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/StringRepo.php';
require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/Replacer.php';

// ── 1 & 2: the attack is neutralized, and encoded per the correct context ──

unit('MLT_Tokenizer: a malicious stored translation cannot execute as HTML in a text node', function (): void {
    $html = '<div><h1>Welcome</h1></div>';
    // $onText receives $normalized as MLT_Tokenizer::normalize() produces it —
    // whitespace-collapsed, but NOT lowercased. MLT_Replacer's real callback
    // does its own mb_strtolower() before the dict lookup; matching the exact
    // case here, not a lowercased key, is what a real callback receives.
    $out = MLT_Tokenizer::walk($html, function (string $normalized): ?string {
        return $normalized === 'Welcome' ? '<img src=x onerror=alert(1)>' : null;
    });

    assert_false(str_contains($out, '<img'), 'the payload must never appear as a live tag');
    assert_eq('<div><h1>&lt;img src=x onerror=alert(1)&gt;</h1></div>', $out, 'the payload must be HTML-entity-encoded exactly as e() would produce');
});

unit('MLT_Tokenizer: a <script>-bearing translation is neutralized the same way', function (): void {
    $html = '<p>Hello</p>';
    $out = MLT_Tokenizer::walk($html, function (string $normalized): ?string {
        return $normalized === 'Hello' ? '<script>alert(document.cookie)</script>' : null;
    });

    assert_false(str_contains($out, '<script>'), 'no live <script> tag may reach the output');
    assert_eq('<p>&lt;script&gt;alert(document.cookie)&lt;/script&gt;</p>', $out);
});

// ── 3: legitimate translation content continues to render correctly ────────

unit('MLT_Tokenizer: ordinary translated text renders unchanged', function (): void {
    $html = '<p>Hello there</p>';
    $out = MLT_Tokenizer::walk($html, function (string $normalized): ?string {
        return $normalized === 'Hello there' ? 'Bonjour' : null;
    });
    assert_eq('<p>Bonjour</p>', $out);
});

unit('MLT_Tokenizer: a translation with legitimate special characters displays correctly, not corrupted or double-escaped', function (): void {
    $html = '<p>Cost</p>';
    $out = MLT_Tokenizer::walk($html, function (string $normalized): ?string {
        return $normalized === 'Cost' ? 'Prix < 50€ & garanti' : null;
    });
    // A browser renders this back to exactly the translator's literal text.
    assert_eq('<p>Prix &lt; 50€ &amp; garanti</p>', $out);
});

unit('MLT_Tokenizer: attribute substitution is untouched by this fix (separate, already-sound escaping)', function (): void {
    $html = '<input type="submit" value="Send">';
    $out = MLT_Tokenizer::walk($html, function (string $normalized): ?string {
        return $normalized === 'Send' ? 'Envoyer "now"' : null;
    });
    assert_eq('<input type="submit" value="Envoyer &quot;now&quot;">', $out);
});

// ── 4: the real mlt.manage save -> publish -> replace pipeline still works ──

MultilangTranslate::ensureSchema();

$tenant = current_tenant_id();
$suffix = bin2hex(random_bytes(4));

$cleanup = static function () use ($tenant, $suffix): void {
    $ids = Database::rows(
        "SELECT id FROM multilangtranslate_strings WHERE tenant_id = ? AND source_text LIKE ?",
        [$tenant, "__probe-mlt-xss-$suffix%"]
    );
    foreach ($ids as $row) {
        Database::query('DELETE FROM multilangtranslate_translations WHERE string_id = ?', [$row['id']]);
        Database::query('DELETE FROM multilangtranslate_strings WHERE id = ?', [$row['id']]);
    }
};
$cleanup();

unit('multilang pipeline: normal and malicious translations saved via saveCell() and published are each handled correctly when the Replacer applies them to a live page', function () use ($tenant, $suffix): void {
    // Both rows are created, saved, and published BEFORE either replace()
    // call: MLT_Replacer::dict() caches its lookup dict per (tenant, locale)
    // for the life of the process, so building it via the first replace()
    // call below must already see both rows, or the second one would read a
    // stale cached dict rather than exercising anything new — an artifact of
    // that cache, not of the fix under test.
    $normalSource = "__probe-mlt-xss-$suffix-normal";
    $attackSource = "__probe-mlt-xss-$suffix-attack";

    $normalId = (int) Database::insert('multilangtranslate_strings', [
        'tenant_id' => $tenant, 'text_hash' => MLT_Tokenizer::hash(MLT_Tokenizer::normalize($normalSource)),
        'source_text' => $normalSource, 'source_area' => 'test', 'occurrences' => 1,
    ]);
    $attackId = (int) Database::insert('multilangtranslate_strings', [
        'tenant_id' => $tenant, 'text_hash' => MLT_Tokenizer::hash(MLT_Tokenizer::normalize($attackSource)),
        'source_text' => $attackSource, 'source_area' => 'test', 'occurrences' => 1,
    ]);

    try {
        MLT_StringRepo::saveCell($tenant, $normalId, 'fr', 'Bonjour probe', 'draft');
        MLT_StringRepo::saveCell($tenant, $attackId, 'fr', '<img src=x onerror=alert(1)>', 'draft');
        $published = MLT_StringRepo::publish($tenant, 'fr');
        assert_true($published >= 2, 'both draft translations must publish');

        $normalOut = MLT_Replacer::replace($tenant, "<p>{$normalSource}</p>", 'fr');
        assert_eq("<p>Bonjour probe</p>", $normalOut, 'a normal published translation must still be applied to live page HTML');

        $attackOut = MLT_Replacer::replace($tenant, "<p>{$attackSource}</p>", 'fr');
        assert_false(str_contains($attackOut, '<img'), 'a malicious stored translation must never reach the live page as a real tag, end-to-end through the real pipeline');
        assert_eq('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $attackOut);
    } finally {
        Database::query('DELETE FROM multilangtranslate_translations WHERE string_id IN (?, ?)', [$normalId, $attackId]);
        Database::query('DELETE FROM multilangtranslate_strings WHERE id IN (?, ?)', [$normalId, $attackId]);
    }
});

$cleanup();
