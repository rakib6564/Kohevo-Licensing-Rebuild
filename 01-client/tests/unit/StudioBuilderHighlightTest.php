<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3d highlight.
 *
 * Autoloader only, no database.
 *
 * A highlighted word is `<span class="sb-hl">` and nothing else: in rich text the class is the only attribute a
 * span may carry, and in a heading the word is a plain-text prop the renderer wraps itself.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RichTextSanitizer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

unit('highlight: rich text keeps exactly class="sb-hl" on a span, in the validator and the sanitizer', function (): void {
    $ok = '<p>Make it <span class="sb-hl">count</span> today</p>';
    assert_eq(null, FieldSchema::validateRichText($ok), 'the validator accepts the highlight span');
    assert_eq($ok, RichTextSanitizer::sanitize($ok));
    assert_eq('<p>Make it <span class="sb-hl">count</span></p>', RichTextSanitizer::sanitize("<p>Make it <span class='sb-hl'>count</span></p>"), 'single quotes normalise');
    assert_eq('<p><span class="sb-hl">a</span></p>', RichTextSanitizer::sanitize('<p><SPAN CLASS="sb-hl">a</SPAN></p>'));
});

unit('highlight: any other span attribute is refused on write and dropped on output', function (): void {
    foreach ([
        '<span class="other">x</span>',
        '<span class="sb-hl other">x</span>',
        '<span class="sb-hl" id="a">x</span>',
        '<span class="sb-hl" onclick="x()">x</span>',
        '<span class="sb-hl" style="color:red">x</span>',
        '<span id="a">x</span>',
        '<span class="sb-hl"x>x</span>',
    ] as $html) {
        assert_true(FieldSchema::validateRichText($html) !== null, 'refused on write: ' . $html);
        $out = RichTextSanitizer::sanitize($html);
        assert_true(!str_contains($out, 'onclick') && !str_contains($out, 'style=') && !str_contains($out, 'id='), 'clean on output: ' . $html);
        assert_eq(0, preg_match('/<span(?! class="sb-hl">)[^>]+>/', $out), "a span out of {$html} carries nothing but the highlight class: {$out}");
    }
});

unit('highlight: a heading wraps its first matching word, as escaped text only', function (): void {
    assert_eq('Get <span class="sb-hl">started</span> now', Html::highlighted('Get started now', 'started'));
    assert_eq('<span class="sb-hl">a</span> a', Html::highlighted('a a', 'a'), 'only the first occurrence');
    assert_eq('Get started', Html::highlighted('Get started', ''), 'no word, no span');
    assert_eq('Get started', Html::highlighted('Get started', 'absent'), 'a word that is not there changes nothing');
    assert_eq('Héllo <span class="sb-hl">wörld</span>!', Html::highlighted('Héllo wörld!', 'wörld'), 'multibyte safe');
    assert_eq('&lt;b&gt;x&lt;/b&gt; <span class="sb-hl">&lt;i&gt;</span>', Html::highlighted('<b>x</b> <i>', '<i>'), 'markup in the word is text');
    assert_eq('A &amp; <span class="sb-hl">B</span>', Html::highlighted('A & B', 'B'), 'the rest is escaped');
});
