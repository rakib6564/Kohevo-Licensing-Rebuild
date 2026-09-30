<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 8B constrained HTML/CSS import.
 *
 * Dependency-free (no database): the pure converter end to end (runtime
 * capability and fail-closed, raw guards, encoding, parser budgets, the
 * security boundary, HTML -> canonical mapping and heuristics, the bounded
 * CSS subset and exact token matching, media hints, determinism), the IR
 * bounds, the report extension (8A output unchanged), the HTTP transport of
 * `import_html`, and static architecture guards. Tenancy, permissions,
 * persistence, media resolution and audit are covered by the Phase 8B
 * integration suite.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioP8bUnitStandalone = true;
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Http\StudioApiRateLimiter;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Package\Html\HtmlCssParser;
use Slate\Module\StudioBuilder\Package\Html\HtmlCssValues;
use Slate\Module\StudioBuilder\Package\Html\HtmlImportConverter;
use Slate\Module\StudioBuilder\Package\Html\HtmlImportIr;
use Slate\Module\StudioBuilder\Package\Html\HtmlImportIssues;
use Slate\Module\StudioBuilder\Package\StudioImportReport;
use Slate\Module\StudioBuilder\Package\StudioPackageFormat;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;

const SB8H_CORE_TYPES = ['core.hero', 'core.heading', 'core.rich_text', 'core.image', 'core.button', 'core.feature_list', 'core.container'];

/** @return array<string, mixed> */
function sb8h_convert(string $html, string $css = '', array $meta = [], ?array $theme = null): array
{
    return (new HtmlImportConverter())->convert($html, $css, $meta + ['slug' => 'imported'], $theme ?? ThemeResolver::DEFAULT_TOKENS);
}

/** @return array<string, mixed> */
function sb8h_doc(array $result): array
{
    assert_true($result['package'] !== null, 'expected a package, got issues: ' . json_encode(array_slice($result['issues'], 0, 5)));
    return $result['package']['items'][0]['document'];
}

/** Every block (depth-first) of a document. @return list<array<string, mixed>> */
function sb8h_blocks(array $doc): array
{
    $out = [];
    $stack = [];
    foreach (array_reverse($doc['sections']) as $s) {
        foreach (array_reverse($s['blocks']) as $b) {
            $stack[] = $b;
        }
    }
    while ($stack !== []) {
        $b = array_pop($stack);
        $out[] = $b;
        foreach (array_reverse($b['children']) as $c) {
            $stack[] = $c;
        }
    }
    return $out;
}

/** @return list<string> */
function sb8h_types(array $doc): array
{
    return array_column(sb8h_blocks($doc), 'type');
}

/** @return list<string> */
function sb8h_codes(array $result, ?string $severity = null): array
{
    return array_values(array_map(static fn(array $i): string => $i['code'], array_filter($result['issues'], static fn(array $i): bool => $severity === null || $i['severity'] === $severity)));
}

/** @return list<array<string, mixed>> */
function sb8h_of(array $doc, string $type): array
{
    return array_values(array_filter(sb8h_blocks($doc), static fn(array $b): bool => $b['type'] === $type));
}

function sb8h_error(array $result, string $code): void
{
    assert_true($result['package'] === null, "expected refusal with {$code}");
    assert_true(in_array($code, sb8h_codes($result, 'error'), true), "expected error {$code}, got " . json_encode(sb8h_codes($result)));
}

/** The document validates with the CURRENT canonical validator and registry (media ids are package-local keys). */
function sb8h_assert_valid(array $doc): void
{
    try {
        ValidatedDocument::from($doc, BlockRegistry::withCoreFoundationBlocks(), ['media_exists' => static fn(int $id): bool => true]);
    } catch (\Throwable $e) {
        assert_true(false, 'document must validate: ' . json_encode(method_exists($e, 'errors') ? $e->errors() : $e->getMessage()));
    }
    foreach (sb8h_blocks($doc) as $b) {
        assert_true(in_array($b['type'], SB8H_CORE_TYPES, true), 'only current core blocks: ' . $b['type']);
        if ($b['type'] === 'core.rich_text') {
            assert_eq(null, FieldSchema::validateRichText($b['props']['content']), 'every rich text passes the write validator');
        }
    }
}

// ── 1. Runtime capability ──────────────────────────────────────────────────

unit('phase8b unit: DOMDocument and libxml are available to this runtime, and a runtime without them fails closed with html_import_unavailable before any parsing', function (): void {
    assert_true(class_exists(\DOMDocument::class) && extension_loaded('dom'), 'ext-dom');
    assert_true(extension_loaded('libxml') && defined('LIBXML_NONET'), 'libxml with LIBXML_NONET');
    assert_true(HtmlImportConverter::runtimeSupported());
    $r = (new HtmlImportConverter(static fn(): bool => false))->convert(str_repeat('<p>x</p>', 7000), '', ['slug' => 'x'], ThemeResolver::DEFAULT_TOKENS);
    assert_eq(['html_import_unavailable'], sb8h_codes($r), 'unavailable is the ONLY finding — no guard or parser ran');
    assert_eq(null, $r['package']);
});

// ── 2. Raw source guards ───────────────────────────────────────────────────

unit('phase8b unit: raw guards refuse oversize sources, too many tags, invalid UTF-8, NUL, C0 controls and DOCTYPE internal subsets — before parsing', function (): void {
    sb8h_error(sb8h_convert(str_repeat('a', 524289)), 'source_too_large');
    sb8h_error(sb8h_convert('<p>x</p>', str_repeat(' ', 131073)), 'source_too_large');
    sb8h_error(sb8h_convert('<style>' . str_repeat(' ', 70000) . '</style><p>x</p>', str_repeat(' ', 70000)), 'source_too_large');
    sb8h_error(sb8h_convert(str_repeat('<', 12001)), 'source_limit_exceeded');
    sb8h_error(sb8h_convert("<p>caf\xC3\x28</p>"), 'invalid_source');
    sb8h_error(sb8h_convert("<p>a\x00b</p>"), 'invalid_source');
    sb8h_error(sb8h_convert("<p>a\x07b</p>"), 'invalid_source');
    sb8h_error(sb8h_convert('<p>x</p>', "p{color:red}\x01"), 'invalid_source');
    sb8h_error(sb8h_convert('<!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]><p>&x;</p>'), 'invalid_source');
    sb8h_error(sb8h_convert("  \n "), 'invalid_source');
    $ok = sb8h_convert("<h2>Tab\tand\nnewline\r\nare fine</h2>");
    assert_eq('Tab and newline are fine', sb8h_of(sb8h_doc($ok), 'core.heading')[0]['props']['text']);
});

// ── 3. Encoding ────────────────────────────────────────────────────────────

unit('phase8b unit: UTF-8 survives libxml\'s charset-less HTML parser exactly (é, —, ✓, emoji) in headings, rich text, alt text and titles', function (): void {
    $r = sb8h_convert('<title>Café — ✓ 🎉</title><article><h2>Café — ✓ 🎉</h2><p>Crème brûlée — ✓ 🎉 日本</p><img src="/uploads/café.jpg" alt="Café ✓"></article>');
    $doc = sb8h_doc($r);
    assert_eq('Café — ✓ 🎉', $r['package']['items'][0]['title'], 'the <title> becomes the default page title');
    assert_eq('Café — ✓ 🎉', sb8h_of($doc, 'core.heading')[0]['props']['text']);
    assert_eq('<p>Crème brûlée — ✓ 🎉 日本</p>', sb8h_of($doc, 'core.rich_text')[0]['props']['content']);
    assert_eq('Café ✓', sb8h_of($doc, 'core.image')[0]['props']['media']['alt']);
    assert_eq('/uploads/café.jpg', $r['package']['items'][0]['media'][0]['path']);
    sb8h_assert_valid($doc);
});

unit('phase8b unit: malformed HTML (unclosed / misnested tags, stray closers, unknown elements) still yields a valid document', function (): void {
    $doc = sb8h_doc(sb8h_convert('<article><h2>Title<p>one<p>two <b>bold <i>both</b> tail</i></div></span><x-card>custom text</x-card><ul><li>a<li>b</ul></article>'));
    sb8h_assert_valid($doc);
    $json = json_encode($doc);
    foreach (['one', 'two', 'bold', 'custom text'] as $t) {
        assert_true(str_contains($json, $t), "kept: {$t}");
    }
});

unit('phase8b unit: libxml HTML4-parser differentials are corrected — HTML5 content after <title> is not lost in <head>, and void source/track/embed/param/keygen do not swallow their siblings', function (): void {
    $doc = sb8h_doc(sb8h_convert('<title>T</title><article><h2>In head per libxml</h2><p>still imported</p></article>'));
    assert_true(str_contains(json_encode($doc), 'still imported'), 'content libxml left in <head> is body content');

    $r = sb8h_convert('<article><h2>V</h2><picture><source srcset="/uploads/x.webp"><img src="/uploads/p.jpg" alt="P"></picture>'
        . '<p>a<embed src="x">AFTER_EMBED <param name="x">AFTER_PARAM <track src="x">AFTER_TRACK <keygen>AFTER_KEYGEN <wbr>AFTER_WBR</p></article>');
    $doc = sb8h_doc($r);
    $json = json_encode($doc);
    foreach (['AFTER_EMBED', 'AFTER_PARAM', 'AFTER_TRACK', 'AFTER_KEYGEN', 'AFTER_WBR'] as $mark) {
        assert_true(str_contains($json, $mark), "{$mark}: a void element's (misparsed) siblings are kept");
    }
    assert_eq('/uploads/p.jpg', $r['package']['items'][0]['media'][0]['path'], 'the <img> after <source> in <picture> is kept');
    foreach (['source', 'embed', 'param', 'track', 'keygen'] as $t) {
        assert_true(in_array($t, array_column($r['issues'], 'detail'), true), "the {$t} element itself is reported");
    }
    assert_true(!str_contains($json, 'srcset') && !str_contains($json, 'webp'));
    sb8h_assert_valid($doc);
});

// ── 4. Parser budgets ──────────────────────────────────────────────────────

unit('phase8b unit: traversal budgets — > 5,000 elements, > 10,000 nodes, depth > 64 and libxml\'s own depth limit are fatal; > 32 attributes are ignored with a warning', function (): void {
    sb8h_error(sb8h_convert('<div>' . str_repeat('<i></i>', 5001) . '</div>'), 'source_limit_exceeded');
    sb8h_error(sb8h_convert('<div>' . str_repeat('<b>x</b> ', 3400) . '</div>'), 'source_limit_exceeded');
    sb8h_error(sb8h_convert(str_repeat('<div>', 70) . 'deep' . str_repeat('</div>', 70)), 'source_limit_exceeded');
    $libxml = sb8h_convert(str_repeat('<div>', 300) . 'deep' . str_repeat('</div>', 300));
    sb8h_error($libxml, 'source_limit_exceeded');
    assert_eq('depth', $libxml['issues'][0]['detail'] ?? null, 'libxml "Excessive depth" (silent truncation) is detected');

    $attrs = implode(' ', array_map(static fn(int $i): string => "data-a{$i}=\"x\"", range(1, 33)));
    $r = sb8h_convert("<article><h2>Links</h2><p>see <a href=\"/x\" {$attrs}>this</a></p></article>");
    assert_true(in_array('source_limit_exceeded', sb8h_codes($r, 'warning'), true));
    assert_true(!str_contains(json_encode(sb8h_doc($r)), 'href'), 'the over-attributed link lost its (ignored) href');
    assert_eq(64, HtmlCssParser::MAX_PER_RULE);
});

unit('phase8b unit: CSS budgets — > 2,000 rules, > 5,000 declarations, > 64 per rule, selectors > 256, values > 256 and > 32 inline declarations are fatal', function (): void {
    sb8h_error(sb8h_convert('<p>x</p>', str_repeat('.a{color:red}', 2001)), 'source_limit_exceeded');
    sb8h_error(sb8h_convert('<p>x</p>', str_repeat('.a{' . str_repeat('color:red;', 63) . '}', 80)), 'source_limit_exceeded');
    sb8h_error(sb8h_convert('<p>x</p>', '.a{' . str_repeat('color:red;', 65) . '}'), 'source_limit_exceeded');
    sb8h_error(sb8h_convert('<p>x</p>', '.' . str_repeat('a', 300) . '{color:red}'), 'source_limit_exceeded');
    sb8h_error(sb8h_convert('<p>x</p>', '.a{font-family:' . str_repeat('a', 300) . '}'), 'source_limit_exceeded');
    sb8h_error(sb8h_convert('<p style="' . str_repeat('color:red;', 33) . '">x</p>'), 'source_limit_exceeded');
});

unit('phase8b unit: output overflow is refused, never truncated — > 50 sections or > 250 blocks is output_limit_exceeded', function (): void {
    sb8h_error(sb8h_convert(str_repeat('<section><h2>S</h2><p>t</p></section>', 51)), 'output_limit_exceeded');
    $r = sb8h_convert(str_repeat('<section>' . str_repeat('<h2>H</h2><img src="/uploads/a.jpg">', 27) . '</section>', 5));
    sb8h_error($r, 'output_limit_exceeded');
    assert_eq('blocks', $r['issues'][0]['detail'] ?? null, '270 blocks > 250');
    $ok = sb8h_convert(str_repeat('<section><h2>S</h2><p>t</p></section>', 50));
    assert_eq(50, count(sb8h_doc($ok)['sections']));
});

// ── 5. Security boundary ───────────────────────────────────────────────────

unit('phase8b unit: executable and embedded content is stripped WITH its subtree and reported; nothing of it reaches the document', function (): void {
    $html = '<article><h2>Safe</h2><p>kept</p>'
        . '<script>SCRIPT_MARK()</script><noscript><p>NOSCRIPT_MARK</p></noscript><iframe srcdoc="<script>SRCDOC_MARK</script>">IFRAME_MARK</iframe>'
        . '<object data="x">OBJECT_MARK</object><embed src="EMBED_MARK"><applet>APPLET_MARK</applet>'
        . '<form action="/steal"><label>LABEL_MARK</label><input value="INPUT_MARK"><textarea>TEXTAREA_MARK</textarea><select><option>OPTION_MARK</option></select><button>BUTTON_MARK</button><fieldset>FIELDSET_MARK</fieldset><output>OUTPUT_MARK</output></form>'
        . '<svg><script>SVG_SCRIPT_MARK</script><foreignObject><p>FOREIGN_MARK</p></foreignObject></svg><math><mi>MATH_MARK</mi></math>'
        . '<template><p>TEMPLATE_MARK</p></template><canvas>CANVAS_MARK</canvas><video><source src="x">VIDEO_MARK</video><audio>AUDIO_MARK</audio>'
        . '<frameset><frame src="x"></frameset>'
        . '<p onclick="ONCLICK_MARK()" onmouseover="x" data-secret="DATA_MARK">handlers</p></article>';
    $r = sb8h_convert($html);
    $json = json_encode(sb8h_doc($r));
    foreach (['SCRIPT', 'NOSCRIPT', 'SRCDOC', 'IFRAME', 'OBJECT', 'EMBED', 'APPLET', 'LABEL', 'INPUT', 'TEXTAREA', 'OPTION', 'BUTTON', 'FIELDSET', 'OUTPUT', 'SVG_SCRIPT', 'FOREIGN', 'MATH', 'TEMPLATE', 'CANVAS', 'VIDEO', 'AUDIO', 'ONCLICK', 'DATA'] as $mark) {
        assert_true(!str_contains($json, $mark . '_MARK'), "{$mark} content must not survive");
    }
    foreach (['<script', 'onclick', 'onmouseover', 'srcdoc', 'data-secret', 'action', '<svg', '<iframe', '<form'] as $needle) {
        assert_true(!str_contains(strtolower($json), strtolower($needle)), "{$needle} must not survive");
    }
    assert_true(str_contains($json, 'kept') && str_contains($json, 'handlers'), 'safe content around it is kept');
    $stripped = array_values(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'security_stripped'));
    $details = array_column($stripped, 'detail');
    foreach (['script', 'noscript', 'iframe', 'object', 'embed', 'applet', 'form', 'svg', 'math', 'template', 'canvas', 'video', 'audio', 'attribute:onclick'] as $d) {
        assert_true(in_array($d, $details, true), "reported: {$d}");
    }
    foreach ($stripped as $issue) {
        assert_true(str_starts_with($issue['path'], 'html:L'), 'with a source location');
    }
    assert_true(!in_array('label', $details, true), 'descendants of a stripped element are not traversed (the form was dropped whole)');
    sb8h_assert_valid(sb8h_doc($r));
});

unit('phase8b unit: every URL passes FieldSchema::isSafeUrl — javascript:, vbscript:, data:, file:, blob:, about:, protocol-relative and control characters downgrade links to text and drop images', function (): void {
    $bad = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'jav&#x09;ascript:alert(1)', 'vbscript:msgbox', 'data:text/html,<b>x</b>', 'file:///etc/passwd', 'blob:https://x/1', 'about:blank', '//evil.example/x'];
    $html = '<article><h2>Links</h2>';
    foreach ($bad as $i => $u) {
        $html .= '<p>link' . $i . ' <a href="' . htmlspecialchars($u, ENT_QUOTES) . '">text' . $i . '</a></p><img src="' . htmlspecialchars($u, ENT_QUOTES) . '">';
    }
    $html .= '<p><a href="https://example.com/ok" target="_blank">safe</a> <a href="/local">local</a> <a href="mailto:a@b.co">mail</a></p></article>';
    $r = sb8h_convert($html);
    $doc = sb8h_doc($r);
    $json = strtolower(json_encode($doc));
    foreach (['javascript', 'vbscript', 'data:', 'file:', 'blob:', 'about:', 'evil.example'] as $needle) {
        assert_true(!str_contains($json, $needle), "{$needle} must not survive");
    }
    foreach (range(0, count($bad) - 1) as $i) {
        assert_true(str_contains($json, 'text' . $i), "the link text {$i} is kept");
    }
    assert_eq([], sb8h_of($doc, 'core.image'), 'no unsafe image survives');
    assert_true(str_contains($json, '<a href=\"https:\/\/example.com\/ok\" target=\"_blank\" rel=\"noopener noreferrer\">safe<\/a>'), 'a safe external link keeps target _blank + noopener');
    assert_true(str_contains($json, '<a href=\"\/local\">local<\/a>'));
    assert_true(in_array('unsafe_url', sb8h_codes($r), true));
    sb8h_assert_valid($doc);
});

// ── 6. HTML mapping ────────────────────────────────────────────────────────

unit('phase8b unit: h1–h6 become core.heading with the exact level; inline markup inside a heading becomes its plain text', function (): void {
    $doc = sb8h_doc(sb8h_convert('<article><h1>One <em>x</em></h1><p>a</p><p>b</p><ul><li>c</li></ul><h2>Two</h2><h3>Three</h3><h4>Four</h4><h5>Five</h5><h6>Six</h6></article>'));
    $headings = sb8h_of($doc, 'core.heading');
    assert_eq(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_map(static fn(array $b): string => $b['props']['level'], $headings));
    assert_eq('One x', $headings[0]['props']['text']);
    assert_eq(['text', 'level'], array_keys($headings[0]['props']), 'exact core.heading prop shape');
    sb8h_assert_valid($doc);
});

unit('phase8b unit: paragraphs, lists, blockquote, pre/code and definition lists become allowlisted rich text, merged into one block between structural breaks', function (): void {
    $doc = sb8h_doc(sb8h_convert(
        '<article><h2>T</h2><p class="lead" style="margin:0" id="x">One <strong>strong</strong> <b>b</b> <em>em</em> <i>i</i> <u>u</u> <s>s</s> <code>code</code> <span class="c">span</span> <small>small</small><br>line</p>'
        . '<ul><li>a<ul><li>nested</li></ul></li><li><p>para in li</p></li></ul><ol><li>first</li></ol>'
        . '<blockquote><p>quoted</p>loose</blockquote><pre><code>x = 1;' . "\n" . '  y = 2;</code></pre><dl><dt>Term</dt><dd>Def</dd></dl></article>'
    ));
    $rich = sb8h_of($doc, 'core.rich_text');
    assert_eq(1, count($rich), 'adjacent units merge into one block');
    assert_eq(['content'], array_keys($rich[0]['props']));
    assert_eq(
        '<p>One <strong>strong</strong> <b>b</b> <em>em</em> <i>i</i> <u>u</u> <s>s</s> <code>code</code> span small<br>line</p>'
        . '<ul><li>a<ul><li>nested</li></ul></li><li><p>para in li</p></li></ul><ol><li>first</li></ol>'
        . '<blockquote><p>quoted</p><p>loose</p></blockquote><pre><code>x = 1;' . "\n" . '  y = 2;</code></pre><p><strong>Term</strong></p><p>Def</p>',
        $rich[0]['props']['content']
    );
    $json = json_encode($doc);
    foreach (['lead', 'margin', '"x"', 'class'] as $needle) {
        assert_true(!str_contains($json, $needle), "source attribute {$needle} is not persisted");
    }
    sb8h_assert_valid($doc);
});

unit('phase8b unit: prose the current validator falsely flags as SQL/code is dropped per unit with a warning — the rest of the page imports', function (): void {
    $r = sb8h_convert('<article><h2>Guide</h2><p>Insert into the slot and turn.</p><p>Delete from your cart anytime.</p><p>A normal paragraph.</p><p>JavaScript: The Good Parts</p><h3>Drop table salt</h3></article>');
    $doc = sb8h_doc($r);
    $json = json_encode($doc);
    assert_true(str_contains($json, 'A normal paragraph.'), 'the safe unit survives');
    foreach (['Insert into', 'Delete from', 'JavaScript:', 'Drop table'] as $t) {
        assert_true(!str_contains($json, $t), "flagged prose '{$t}' is left out");
    }
    $dropped = array_values(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'unsafe_text_dropped'));
    assert_eq(4, count($dropped), 'one deterministic warning per dropped unit');
    assert_true(FieldSchema::containsExecutableOrSqlFragment('Insert into the slot'), 'the validator itself is unchanged');
    sb8h_assert_valid($doc);
});

unit('phase8b unit: button-like links (class btn/button/cta, role=button, or a lone link in a wrapper) become core.button with the exact link shape; plain links stay inline', function (): void {
    $doc = sb8h_doc(sb8h_convert(
        '<article><h2>Act</h2><p>Read the <a href="/terms">terms</a>.</p>'
        . '<a class="btn btn-outline-primary" href="/a">Outline</a><a role="button" href="/b" target="_blank">Role</a><div class="actions"><a href="/c">Lone</a></div>'
        . '<a class="cta-ghost" href="/d">Ghost</a><a class="btn" href="javascript:x">Bad</a></article>'
    ));
    $buttons = sb8h_of($doc, 'core.button');
    assert_eq(4, count($buttons));
    assert_eq(['link' => ['label' => 'Outline', 'href' => '/a', 'target' => '_self'], 'variant' => 'outline'], $buttons[0]['props']);
    assert_eq(['label' => 'Role', 'href' => '/b', 'target' => '_blank'], $buttons[1]['props']['link']);
    assert_eq('primary', $buttons[2]['props']['variant']);
    assert_eq('ghost', $buttons[3]['props']['variant']);
    $json = json_encode($doc);
    assert_true(str_contains($json, '<a href=\"\/terms\">terms<\/a>'), 'an in-text link stays in the rich text');
    assert_true(str_contains($json, 'Bad') && !str_contains($json, 'javascript'), 'an unsafe button downgrades to its text');
    sb8h_assert_valid($doc);
});

unit('phase8b unit: images — only tenant-local /uploads/ paths become core.image (package-local media key + descriptor); figure/figcaption becomes the caption', function (): void {
    $r = sb8h_convert(
        '<article><h2>Gallery</h2><figure><img src="/uploads/media/a.jpg?v=3" alt="A"><figcaption>Caption A</figcaption></figure>'
        . '<p><img src="/uploads/media/b.png" alt="B"></p><picture><source srcset="/uploads/x.webp"><img src="/uploads/media/a.jpg" alt="Again"></picture>'
        . '<img src="https://cdn.example.com/c.jpg"><img src="/images/local.png"><img src="/uploads/doc.pdf"><img src="data:image/png;base64,AAAA"><img src=""><img src="/uploads/media/d.svg"></article>'
    );
    $doc = sb8h_doc($r);
    $images = sb8h_of($doc, 'core.image');
    assert_eq(4, count($images));
    assert_eq(['media', 'caption'], array_keys($images[0]['props']), 'exact core.image prop shape (defaults filled by the normalizer)');
    assert_eq(['media_id' => 1, 'alt' => 'A'], $images[0]['props']['media']);
    assert_eq('Caption A', $images[0]['props']['caption']);
    assert_eq(1, $images[2]['props']['media']['media_id'], 'the same path reuses its media key');
    assert_eq([['key' => 1, 'path' => '/uploads/media/a.jpg', 'mime' => ''], ['key' => 2, 'path' => '/uploads/media/b.png', 'mime' => ''], ['key' => 3, 'path' => '/uploads/media/d.svg', 'mime' => '']], $r['package']['items'][0]['media'], 'descriptors only — no URL, no binary, no foreign id');
    $details = array_map(static fn(array $i): string => $i['code'] . ':' . ($i['detail'] ?? ''), $r['issues']);
    foreach (['unresolved_media:external_url', 'unresolved_media:not_managed', 'unsupported_media:extension', 'security_stripped:data_uri', 'unsupported_media:no_src'] as $expect) {
        assert_true(in_array($expect, $details, true), "reported {$expect}");
    }
    $json = json_encode($r['package']);
    assert_true(!str_contains($json, 'cdn.example.com') && !str_contains($json, 'base64'), 'no remote or data URL enters the package');
    sb8h_assert_valid($doc);
});

unit('phase8b unit: sections — section/article/main become sections, header/footer are ordinary sections with chrome_not_imported, loose content forms an implicit section; nav, tables, hr, details, dialog are unsupported', function (): void {
    $r = sb8h_convert(
        '<header><h2>Site</h2><p>tagline</p></header><nav><a href="/">Home</a></nav><h2>Loose</h2><p>loose text</p>'
        . '<main><section><h2>A</h2><p>a</p></section><article><h2>B</h2><p>b</p></article></main>'
        . '<section><h2>C</h2><table><tr><td>TABLE_MARK</td></tr></table><hr><details><summary>S</summary>DETAILS_MARK</details><dialog>DIALOG_MARK</dialog><p>c</p></section>'
        . '<footer><p>© 2026</p></footer>'
    );
    $doc = sb8h_doc($r);
    assert_eq(['Site', 'Loose', 'A', 'B', 'C', ''], array_column($doc['sections'], 'label'));
    assert_eq(2, count(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'chrome_not_imported')));
    $unsupported = array_column(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'unsupported_element'), 'detail');
    foreach (['nav', 'table', 'hr', 'details', 'dialog'] as $t) {
        assert_true(in_array($t, $unsupported, true), "unsupported: {$t}");
    }
    $json = json_encode($doc);
    foreach (['TABLE_MARK', 'DETAILS_MARK', 'DIALOG_MARK', 'Home'] as $mark) {
        assert_true(!str_contains($json, $mark), "{$mark} not imported");
    }
    assert_eq('page', $doc['document_type'], 'never a header_partial / footer_partial');
    foreach ($doc['sections'] as $s) {
        assert_eq(['base' => 1], $s['layout']['columns'], 'stacked blocks (the default 12-column layout would place them side by side)');
        assert_eq(null, $s['global_ref']);
    }
    sb8h_assert_valid($doc);
});

unit('phase8b unit: flex/grid wrappers become core.container (direction from the evidence); multi-block children get their own vertical container; depth never exceeds 4', function (): void {
    $css = '.row{display:flex;gap:2rem}.col{display:flex;flex-direction:column}.grid{display:grid;grid-template-columns:1fr 1fr}';
    $doc = sb8h_doc(sb8h_convert('<section><h2>Row</h2><div class="row"><div><h3>A</h3><img src="/uploads/a.jpg"></div><div><h3>B</h3><img src="/uploads/b.jpg"></div></div><div class="col"><p>x</p><a class="btn" href="/y">Y</a></div></section>', $css));
    $containers = sb8h_of($doc, 'core.container');
    assert_eq(['gap' => 'xl', 'direction' => 'horizontal'], $containers[0]['props']);
    assert_eq(['core.container', 'core.container'], array_column($containers[0]['children'], 'type'), 'each multi-block column is wrapped');
    assert_eq('vertical', $containers[0]['children'][0]['props']['direction']);
    assert_eq(['core.heading', 'core.image'], array_column($containers[0]['children'][0]['children'], 'type'));
    assert_eq(['gap' => 'md', 'direction' => 'vertical'], $containers[3]['props'], 'flex-direction: column');
    sb8h_assert_valid($doc);

    $deep = str_repeat('<div class="row"><h3>L</h3>', 8) . '<p>leaf</p>' . str_repeat('</div>', 8);
    $d2 = sb8h_doc(sb8h_convert('<section><h2>Deep</h2>' . $deep . '</section>', $css));
    $max = 0;
    $walk = static function (array $blocks, int $depth) use (&$walk, &$max): void {
        foreach ($blocks as $b) {
            $max = max($max, $depth);
            $walk($b['children'], $depth + 1);
        }
    };
    foreach ($d2['sections'] as $s) {
        $walk($s['blocks'], 1);
    }
    assert_true($max <= CanonicalDocumentSchema::MAX_NESTING_DEPTH, "nesting {$max} <= 4");
    assert_true(str_contains(json_encode($d2), 'leaf'), 'nothing is lost by flattening');
    sb8h_assert_valid($d2);
});

unit('phase8b unit: hero heuristic — eyebrow + one h1 + paragraph + one button + one image becomes core.hero with the exact prop shape; anything more falls back', function (): void {
    $doc = sb8h_doc(sb8h_convert('<section class="hero"><p>New</p><h1>Big idea</h1><p>Sub text</p><a class="btn" href="/go">Go</a><img src="/uploads/h.jpg" alt="Hero"></section>'));
    $hero = sb8h_of($doc, 'core.hero');
    assert_eq(1, count($hero));
    assert_eq(['eyebrow' => 'New', 'heading' => 'Big idea', 'subheading' => 'Sub text', 'primary_cta' => ['label' => 'Go', 'href' => '/go', 'target' => '_self'], 'media' => ['media_id' => 1, 'alt' => 'Hero']], $hero[0]['props']);
    assert_eq(['core.hero'], sb8h_types($doc));
    sb8h_assert_valid($doc);

    foreach ([
        'two headings' => '<section><h1>A</h1><h1>B</h1><p>x</p></section>',
        'h2 heading'   => '<section><h2>A</h2><p>x</p><a class="btn" href="/x">X</a></section>',
        'two buttons'  => '<section><h1>A</h1><a class="btn" href="/x">X</a><a class="btn" href="/y">Y</a></section>',
        'extra list'   => '<section><h1>A</h1><p>x</p><ul><li>y</li></ul></section>',
        'heading only' => '<section><h1>A</h1></section>',
    ] as $case => $html) {
        assert_eq([], sb8h_of(sb8h_doc(sb8h_convert($html)), 'core.hero'), "no hero: {$case}");
    }
});

unit('phase8b unit: feature heuristic — 2–12 uniform cards (heading, optional paragraph, optional one link) become core.feature_list; image cards and mixed children fall back', function (): void {
    $cards = '<div class="grid"><div><h3>Fast</h3><p>Quick</p></div><div><h3>Fresh</h3><p>Today</p><a href="/fresh">More</a></div><a href="/local"><h3>Local</h3><p>Near</p></a></div>';
    $doc = sb8h_doc(sb8h_convert('<section><h2>Why</h2>' . str_replace('<a href="/local"><h3>Local</h3><p>Near</p></a>', '<div><h3>Local</h3><p>Near</p></div>', $cards) . '</section>', '.grid{display:grid;grid-template-columns:repeat(3,1fr)}'));
    $features = sb8h_of($doc, 'core.feature_list');
    assert_eq(1, count($features));
    assert_eq(['columns' => 3, 'items' => [['heading' => 'Fast', 'body' => 'Quick'], ['heading' => 'Fresh', 'body' => 'Today', 'url' => '/fresh'], ['heading' => 'Local', 'body' => 'Near']]], $features[0]['props']);
    sb8h_assert_valid($doc);

    $img = sb8h_doc(sb8h_convert('<section><h2>Why</h2><div class="g"><div><h3>A</h3><img src="/uploads/a.jpg"></div><div><h3>B</h3><p>b</p></div></div></section>'));
    assert_eq([], sb8h_of($img, 'core.feature_list'), 'an image-bearing card disqualifies');
    $mixed = sb8h_doc(sb8h_convert('<section><div><div><h3>A</h3></div><p>loose</p></div></section>'));
    assert_eq([], sb8h_of($mixed, 'core.feature_list'), 'mixed children fall back');
    $many = sb8h_doc(sb8h_convert('<section><div>' . str_repeat('<div><h3>A</h3></div>', 13) . '</div></section>'));
    assert_eq([], sb8h_of($many, 'core.feature_list'), 'more than 12 cards falls back');
});

// ── 7. CSS mapping ─────────────────────────────────────────────────────────

unit('phase8b unit: CSS maps only onto canonical enums — align, padding_y / gap / width quantized (ties to the smaller bucket, style_quantized reported)', function (): void {
    $css = '.s{text-align:center;padding:3rem 0;gap:24px;max-width:1200px}.n{padding-top:64px;padding-bottom:1rem;max-width:none}.j{text-align:justify}';
    $r = sb8h_convert('<section class="s"><h2>A</h2><p>a</p></section><section class="n"><h2>B</h2><p class="j">b</p></section>', $css);
    $doc = sb8h_doc($r);
    [$s1, $s2] = $doc['sections'];
    assert_eq(['base' => 'md'], $s1['layout']['padding_y'], '3rem is a tie between md (2rem) and lg (4rem): the smaller wins');
    assert_eq('lg', $s1['layout']['gap'], '24px = 1.5rem is exactly lg');
    assert_eq('wide', $s1['layout']['width'], '1200px = 75rem -> nearest wide');
    assert_eq(['base' => 'lg'], $s2['layout']['padding_y'], 'max(top, bottom) = 4rem');
    assert_eq('full', $s2['layout']['width']);
    assert_eq(['base' => 'center'], $s1['blocks'][0]['style']['align'], 'text-align inherits to the section content');
    assert_eq(['base' => 'justify'], $s2['blocks'][1]['style']['align']);
    $quantized = array_column(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'style_quantized'), 'detail');
    assert_eq(['padding', 'max-width'], $quantized, 'exact values (gap 1.5rem, 4rem) are not reported');
    sb8h_assert_valid($doc);

    assert_eq(['md', false], HtmlCssValues::quantize(3.0, HtmlCssValues::PADDING_SCALE));
    assert_eq(['xs', true], HtmlCssValues::quantize(0.25, HtmlCssValues::GAP_SCALE));
});

unit('phase8b unit: the quantization scales are exactly StudioStylesheet\'s', function (): void {
    $css = StudioStylesheet::css();
    foreach (HtmlCssValues::PADDING_SCALE as $name => $rem) {
        $value = $rem == 0 ? '0' : ltrim(rtrim(rtrim(number_format($rem, 2, '.', ''), '0'), '.'), '0') . 'rem';
        assert_true(str_contains($css, ".sb-py-{$name}{padding-top:{$value};"), "padding {$name} = {$value}");
    }
    foreach (HtmlCssValues::GAP_SCALE as $name => $rem) {
        $value = $rem == 0 ? '0' : ltrim(rtrim(rtrim(number_format($rem, 2, '.', ''), '0'), '.'), '0') . 'rem';
        assert_true(str_contains($css, ".sb-gap-{$name}{gap:{$value}}"), "gap {$name} = {$value}");
    }
    foreach (HtmlCssValues::WIDTH_SCALE as $name => $rem) {
        assert_true(str_contains($css, '.sb-w-' . $name . '{max-width:' . (int) $rem . 'rem'), "width {$name}");
    }
});

unit('phase8b unit: theme-valued tokens match EXACTLY against the resolved theme (normalized hex/rgb, px==rem, fonts, shadows); no near colours, no custom tokens, unmatched values reported', function (): void {
    $css = '.a{background-color:#FFF;color:rgb(87, 83, 78);border-radius:.5rem;box-shadow:0 1px 2px rgba(0, 0, 0, .06);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:24px}'
        . '.b{color:#57534f;background:#fefefe;font-family:"Brand Sans", serif}';
    $r = sb8h_convert('<article><h2 class="a">Tokens</h2><h3 class="b">Near</h3></article>', $css);
    $doc = sb8h_doc($r);
    [$a, $b] = sb8h_of($doc, 'core.heading');
    assert_eq('surface.primary', $a['style']['surface_token'], '#FFF == #ffffff (surface.primary preferred over surface.page)');
    assert_eq('text.muted', $a['style']['text_token'], 'rgb(87,83,78) == #57534e');
    assert_eq('radius.md', $a['style']['radius_token'], '.5rem == 8px');
    assert_eq('shadow.sm', $a['style']['shadow_token']);
    assert_eq('font.body', $a['style']['font_token']);
    assert_eq('space.md', $a['style']['spacing_token'], '24px == 1.5rem');
    assert_eq(null, $b['style']['text_token'], '#57534f is NOT quantized to text.muted');
    assert_eq(null, $b['style']['surface_token']);
    assert_eq(null, $b['style']['font_token'], 'an external font maps to nothing');
    $unmapped = array_column(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'css_value_unmapped'), 'detail');
    foreach (['color', 'background-color', 'font-family'] as $p) {
        assert_true(in_array($p, $unmapped, true), "unmapped reported: {$p}");
    }
    foreach (sb8h_blocks($doc) as $block) {
        foreach (['surface_token', 'text_token', 'radius_token', 'shadow_token', 'font_token', 'spacing_token'] as $f) {
            $ref = $block['style'][$f];
            assert_true($ref === null || array_key_exists($ref, ThemeResolver::DEFAULT_TOKENS), "registry token only: {$ref}");
        }
    }

    // The tenant's RESOLVED theme decides: a branded surface matches only that tenant's value.
    $branded = ThemeResolver::DEFAULT_TOKENS;
    $branded['surface.primary'] = '#fafafa';
    $branded['surface.page'] = '#fafafa';
    $d2 = sb8h_doc(sb8h_convert('<article><h2 class="x">T</h2></article>', '.x{background-color:#fafafa}', [], $branded));
    assert_eq('surface.primary', sb8h_of($d2, 'core.heading')[0]['style']['surface_token']);
    $d3 = sb8h_doc(sb8h_convert('<article><h2 class="x">T</h2></article>', '.x{background-color:#fafafa}'));
    assert_eq(null, sb8h_of($d3, 'core.heading')[0]['style']['surface_token'], 'not a token value in the default theme');
});

unit('phase8b unit: device visibility — display:none drops content; exact-breakpoint @media display rules map onto visibility.devices; inexact queries are dropped with a warning', function (): void {
    $css = '.gone{display:none}@media (max-width: 767.98px){.desk{display:none}}@media screen and (min-width:1024px){.mob{display:none}}'
        . '@media (min-width:768px) and (max-width:1023px){.tab{display:none}}@media (max-width: 700px){.odd{display:none}}'
        . '.only-lg{display:none}@media (min-width:1024px){.only-lg{display:block}}';
    $r = sb8h_convert('<article><h2>V</h2><p class="gone">GONE_MARK</p><p class="desk">desk</p><h3 class="mob">mob</h3><h3 class="tab">tab</h3><h3 class="odd">odd</h3><h3 class="only-lg">big</h3></article>', $css);
    $doc = sb8h_doc($r);
    assert_true(!str_contains(json_encode($doc), 'GONE_MARK'));
    assert_true(in_array('content_dropped_hidden', sb8h_codes($r), true));
    $by = [];
    foreach (sb8h_blocks($doc) as $b) {
        $by[$b['props']['text'] ?? strip_tags((string) ($b['props']['content'] ?? ''))] = $b['visibility']['devices'];
    }
    assert_eq(['md', 'lg'], $by['desk']);
    assert_eq(['base', 'sm', 'md'], $by['mob']);
    assert_eq(['base', 'sm', 'lg'], $by['tab']);
    assert_eq(['base', 'sm', 'md', 'lg'], $by['odd'], 'an inexact query is ignored');
    assert_eq(['lg'], $by['big'], 'hidden by default, shown on an exact breakpoint');
    assert_true(in_array('media_query', array_column($r['issues'], 'detail'), true));
    sb8h_assert_valid($doc);
    assert_eq(null, HtmlCssParser::mediaBuckets('(max-width: 800px)'));
    assert_eq(['base', 'sm'], HtmlCssParser::mediaBuckets('only screen and (max-width:767px)'));
});

unit('phase8b unit: the cascade — id > class > type, then source order; inline style wins; !important adds no priority; var() resolves one level from :root', function (): void {
    $css = ':root{--brand:#1f2937;--alias:var(--brand)}h2{text-align:right}.c{text-align:center}#i{text-align:justify}.o{text-align:left}.o{text-align:right}'
        . '.imp{text-align:center!important}.imp2{text-align:right}.v{color:var(--brand)}.w{color:var(--alias)}.u{color:var(--missing)}';
    $doc = sb8h_doc(sb8h_convert('<article><h2>T</h2><h2 class="c">C</h2><h2 class="c" id="i">I</h2><h2 class="o">O</h2><h2 class="c" style="text-align:left">S</h2>'
        . '<h2 class="imp imp2">IMP</h2><h2 class="v">V</h2><h2 class="w">W</h2><h2 class="u">U</h2></article>', $css));
    $align = [];
    $text = [];
    foreach (sb8h_of($doc, 'core.heading') as $h) {
        $align[$h['props']['text']] = $h['style']['align']['base'];
        $text[$h['props']['text']] = $h['style']['text_token'];
    }
    assert_eq(['T' => 'right', 'C' => 'center', 'I' => 'justify', 'O' => 'right', 'S' => 'left', 'IMP' => 'right', 'V' => 'right', 'W' => 'right', 'U' => 'right'], $align);
    assert_eq('text.accent', $text['V'], 'var(--brand) = #1f2937 = text.accent');
    assert_eq(null, $text['W'], 'no second level of var()');
    assert_eq(null, $text['U']);
});

unit('phase8b unit: CSS security — @import, url(), expression(), -moz-binding, behavior, @font-face, positioning, z-index, transform, animation, transition, @keyframes, filters, complex selectors and backslash escapes are dropped and reported; no CSS text is ever stored', function (): void {
    $css = '@import url("https://evil.example/x.css");@charset "utf-8";@font-face{font-family:X;src:url(https://evil.example/f.woff)}@keyframes spin{to{transform:rotate(1turn)}}'
        . '@supports (display:grid){.a{color:red}}@layer base{.a{color:red}}@container (min-width:1px){.a{color:red}}@page{margin:0}@namespace svg url(x);'
        . '.a{background:url(https://evil.example/bg.png);width:expression(alert(1));-moz-binding:url(x.xml#b);behavior:url(x.htc);position:fixed;z-index:9;transform:scale(2);animation:spin 1s;transition:all 1s;filter:blur(2px);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);text-align:center}'
        . '.b{position:absolute}.ok{position:static;text-align:right}.m{-moz-binding:none;behavior:none}'
        . 'p::before{content:"x"}a:hover{color:red}[data-x]{color:red}div p{color:red}*{color:red}.x>.y{color:red}'
        . '.\\61 {color:red}.esc{text-align:\\63 enter}';
    $r = sb8h_convert('<article><h2 class="a">A</h2><h2 class="b ok">B</h2><h2 class="esc">E</h2></article>', $css);
    $doc = sb8h_doc($r);
    $details = array_column(array_filter($r['issues'], static fn(array $i): bool => $i['code'] === 'unsupported_css'), 'detail');
    foreach (['@import', '@charset', '@font-face', '@keyframes', '@supports', '@layer', '@container', '@page', '@namespace', 'value', 'property:binding', 'property:behavior', 'property:position',
        'property:z-index', 'property:transform', 'property:animation', 'property:transition', 'property:filter', 'property:backdrop-filter',
        'pseudo_element', 'pseudo_class', 'attribute_selector', 'combinator', 'universal_selector', 'escape'] as $expect) {
        assert_true(in_array($expect, $details, true), "reported: {$expect} (got " . json_encode(array_values(array_unique($details))) . ')');
    }
    $headings = sb8h_of($doc, 'core.heading');
    assert_eq('center', $headings[0]['style']['align']['base'], 'the safe declaration of the same rule still applies');
    assert_eq('right', $headings[1]['style']['align']['base'], 'position:static is harmless');
    assert_eq('left', $headings[2]['style']['align']['base'], 'an escaped value fails closed');
    $json = json_encode($doc);
    foreach (['evil.example', 'url(', 'expression', 'rotate', 'blur', 'z-index', 'position', 'px', 'transform', 'color:'] as $needle) {
        assert_true(!str_contains($json, $needle), "no CSS text in the document: {$needle}");
    }
});

// ── 8. Determinism, IR bounds, invariants ──────────────────────────────────

unit('phase8b unit: identical HTML/CSS + theme -> byte-identical conversion (package, hashes, issues)', function (): void {
    $html = '<section class="hero"><p>New</p><h1>Hi</h1><p>Sub</p><a class="btn" href="/x">X</a></section><section><h2>S</h2><p>one <a href="javascript:x">bad</a></p><script>x</script><img src="https://x.test/a.png"></section>';
    $css = '.hero{padding:3rem 0}';
    $a = sb8h_convert($html, $css);
    $b = sb8h_convert($html, $css);
    assert_eq(json_encode($a), json_encode($b));
    assert_eq(StudioPackageFormat::packageHash($a['package']), $a['package_hash']);
    assert_eq([], StudioPackageFormat::validate($a['package']), 'the synthesized package passes the 8A envelope validation');
    assert_true($a['source_hash'] !== sb8h_convert($html . ' ', $css)['source_hash']);
});

unit('phase8b unit: the IR is bounded plain data — > 2,000 nodes, depth > 8, > 250 children, strings > 50,000 and non-data values are refused', function (): void {
    $leaf = HtmlImportIr::node('heading', 'html:', ['text' => ['text' => 'x', 'level' => 'h2']]);
    assert_eq(null, HtmlImportIr::check([['loc' => 'html:', 'label' => '', 'layout' => [], 'visibility' => null, 'nodes' => [$leaf]]]));
    $sec = static fn(array $nodes): array => [['loc' => 'html:', 'label' => '', 'layout' => [], 'visibility' => null, 'nodes' => $nodes]];
    assert_true(HtmlImportIr::check(array_fill(0, 9, $sec(array_fill(0, 240, $leaf))[0])) !== null, '> 2,000 nodes');
    $deep = $leaf;
    for ($i = 0; $i < 9; $i++) {
        $deep = HtmlImportIr::node('container', 'html:', ['children' => [$deep]]);
    }
    assert_true(HtmlImportIr::check($sec([$deep])) !== null, 'depth > 8');
    assert_true(HtmlImportIr::check($sec([HtmlImportIr::node('container', 'html:', ['children' => array_fill(0, 251, $leaf)])])) !== null, '> 250 children');
    assert_true(HtmlImportIr::check($sec([HtmlImportIr::node('rich', 'html:', ['text' => ['html' => str_repeat('a', 50001)]])])) !== null, 'string bound');
    assert_true(HtmlImportIr::check($sec([HtmlImportIr::node('rich', 'html:', ['text' => ['html' => static fn() => 1]])])) !== null, 'no closures');
    assert_true(HtmlImportIr::check($sec([HtmlImportIr::node('script', 'html:')])) !== null, 'known node types only');
});

unit('phase8b unit: invariants on a hostile page — no raw CSS, no source HTML outside rich text, no handlers, no executable tags, no remote image, no tenant id, only core blocks, every block valid', function (): void {
    $html = '<html><head><title>T</title><base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example"><link rel="stylesheet" href="https://evil.example/s.css"><style>.x{color:red}</style></head>'
        . '<body data-tenant_id="2"><section class="x" style="background:url(javascript:alert(1))" tenant_id="3"><h1 onclick="a()">Title</h1><p>Body <img src="https://evil.example/i.png" onerror="alert(1)"></p>'
        . '<a class="btn" href="https://evil.example/go">Go</a><div class="booking-services" data-module="booking.services">booking</div></section></body></html>';
    $r = sb8h_convert($html, '.x{position:fixed}');
    $doc = sb8h_doc($r);
    $json = strtolower(json_encode($r['package']));
    foreach (['position', 'color:red', 'url(', 'javascript', 'onclick', 'onerror', '<img', '<script', '<style', '<section', '<h1', 'refresh', 'stylesheet', 'tenant_id', 'booking.services', 'data-'] as $needle) {
        assert_true(!str_contains($json, $needle), "must not appear: {$needle}");
    }
    assert_true(str_contains($json, 'evil.example\/go'), 'a safe external https link is an ordinary button link');
    assert_eq([], sb8h_of($doc, 'core.image'), 'no remote URL becomes an image');
    assert_eq([], $r['package']['items'][0]['media']);
    sb8h_assert_valid($doc);
    foreach (sb8h_blocks($doc) as $b) {
        $registry = BlockRegistry::withCoreFoundationBlocks();
        assert_true($registry->get($b['type'])->schema()->validate($b['props'])->isValid(), 'props match the current registry schema: ' . $b['type']);
    }
});

// ── 9. Report ──────────────────────────────────────────────────────────────

unit('phase8b unit: the ONE import report — kohevo_json output keeps its exact Phase 8A keys; html_css adds source_hash + conversion; converter issues are capped per code', function (): void {
    $json = (new StudioImportReport('create', true, str_repeat('a', 64)))->toArray(null);
    assert_eq(['ok', 'dry_run', 'source_kind', 'mode', 'package_hash', 'valid', 'can_commit', 'summary', 'issues', 'dependencies', 'planned_actions', 'preview_documents', 'committed'], array_keys($json));
    assert_eq('kohevo_json', $json['source_kind']);
    $html = new StudioImportReport('create', true, str_repeat('a', 64), StudioImportReport::SOURCE_HTML_CSS);
    $html->setSource(str_repeat('b', 64), ['converter_version' => 1]);
    $out = $html->toArray(null);
    assert_eq('html_css', $out['source_kind']);
    assert_eq(str_repeat('b', 64), $out['source_hash']);
    assert_eq(['converter_version' => 1], $out['conversion']);

    $issues = new HtmlImportIssues('html');
    for ($i = 0; $i < 130; $i++) {
        $issues->warning('unsafe_url', 'html:L1 p', 'x');
    }
    $list = $issues->toList();
    assert_eq(101, count($list));
    assert_eq(['issues_truncated', 'unsafe_url'], [$list[100]['code'], $list[100]['detail']]);
    assert_eq('30 more \'unsafe_url\' finding(s) were not listed.', $list[100]['message']);
});

// ── 10. HTTP transport ─────────────────────────────────────────────────────

unit('phase8b unit: import_html is a CSRF-guarded JSON POST with strict fields — no tenant, explicit dry_run, slug for new pages, page/landing only, replace needs target + expected revision; it shares the package rate-limit bucket', function (): void {
    $api = new StudioAuthoringApi(StudioRuntimeFactory::build()->app);
    $editor = StudioActor::authenticated(7, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
    $post = static fn(array $body, bool $csrf = true, string $type = 'application/json', ?string $site = 'same-origin'): StudioApiRequest => new StudioApiRequest('POST', 'import_html', [], (string) json_encode($body), $type, $csrf, $site);
    $first = static fn($r): array => $r->payload['error']['details']['errors'][0];
    $base = ['html' => '<p>x</p>', 'dry_run' => true, 'slug' => 'x'];

    assert_eq(405, $api->handle(new StudioApiRequest('GET', 'import_html'), $editor)->status);
    assert_eq(403, $api->handle($post($base, false), $editor)->status, 'CSRF token required');
    assert_eq(403, $api->handle($post($base, true, 'application/json', 'cross-site'), $editor)->status, 'same-origin only');
    assert_eq(415, $api->handle($post($base, true, 'text/html'), $editor)->status, 'JSON only');
    assert_eq(413, $api->handle(new StudioApiRequest('POST', 'import_html', [], str_repeat(' ', StudioAuthoringApi::MAX_BODY_BYTES + 1), 'application/json', true, 'same-origin'), $editor)->status);
    assert_eq(401, $api->handle($post($base), StudioActor::guest())->status);
    $r = $api->handle($post($base + ['tenant_id' => 3]), $editor);
    assert_eq(['unknown_field', '$.tenant_id'], [$first($r)['code'], $first($r)['path']], 'the request cannot name a tenant');
    assert_eq('unknown_field', $first($api->handle($post($base + ['package' => []]), $editor))['code']);
    assert_eq('$.html', $first($api->handle($post(['html' => ['x'], 'dry_run' => true, 'slug' => 'x']), $editor))['path']);
    assert_eq('$.dry_run', $first($api->handle($post(['html' => '<p>x</p>', 'slug' => 'x']), $editor))['path']);
    assert_eq('$.slug', $first($api->handle($post(['html' => '<p>x</p>', 'dry_run' => true]), $editor))['path'], 'a new page needs a slug');
    assert_eq('$.slug', $first($api->handle($post(array_merge($base, ['slug' => 'Bad Slug'])), $editor))['path']);
    assert_eq('$.page_type', $first($api->handle($post($base + ['page_type' => 'header_partial']), $editor))['path'], 'never a chrome partial');
    assert_eq('$.title', $first($api->handle($post($base + ['title' => '<b>x</b>']), $editor))['path']);
    assert_eq('$.target_page_id', $first($api->handle($post(['html' => '<p>x</p>', 'dry_run' => false, 'mode' => 'replace_draft']), $editor))['path']);
    assert_eq('$.expected_revision_id', $first($api->handle($post(['html' => '<p>x</p>', 'dry_run' => false, 'mode' => 'replace_draft', 'target_page_id' => 3]), $editor))['path']);
    assert_eq('$.media_map', $first($api->handle($post($base + ['media_map' => ['https://x/a.jpg' => 3]]), $editor))['path']);

    assert_true(in_array('import_html', StudioApiRateLimiter::PACKAGE_ACTIONS, true));
    $limiter = new StudioApiRateLimiter(static fn(): int => 1000);
    $bucket = null;
    for ($i = 0; $i < StudioApiRateLimiter::MAX_PACKAGES; $i++) {
        assert_true($limiter->hit($bucket, 'POST', 'import_html'));
    }
    assert_false($limiter->hit($bucket, 'POST', 'import_package'), 'HTML and package imports share one bucket');
});

// ── 11. Static architecture guards ─────────────────────────────────────────

unit('phase8b architecture: the converter is pure — no network, filesystem, database, audit, transaction or publish; only DOMDocument::loadHTML with LIBXML_NONET, never NOENT/PARSEHUGE/NOIMPLIED; explicit-stack traversal', function (): void {
    $files = glob(SLATE_ROOT . '/src/Module/StudioBuilder/Package/Html/*.php');
    assert_eq(7, count($files));
    $all = '';
    foreach ($files as $file) {
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file));
        $all .= $code;
        foreach (['curl_', 'file_get_contents', 'fopen', 'fsockopen', 'stream_socket', 'file_put_contents', 'readfile', 'Database::', '->exec(', 'studiobuilder_', 'AuditLog', 'beginTransaction', '->publish(', 'publishWorkingRevision', 'Media::', 'DOMXPath', 'LIBXML_NOENT', 'LIBXML_PARSEHUGE', 'LIBXML_HTML_NOIMPLIED', 'loadXML', 'eval(', 'StudioPackageService', 'tenant_id', 'published_revision_id'] as $needle) {
            assert_true(!str_contains($code, $needle), basename($file) . " must not contain {$needle}");
        }
    }
    assert_eq(1, substr_count($all, '->loadHTML('), 'exactly one parser entry point');
    assert_true(str_contains($all, 'loadHTML($prepared, LIBXML_NONET)'));
    assert_true(str_contains($all, 'mb_encode_numericentity'), 'charset-safe normalization');
    // No recursion in the DOM walk: the reader never calls walk() from inside walk().
    $reader = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Package/Html/HtmlSourceReader.php');
    assert_eq(1, substr_count($reader, '$this->walk('));
});

unit('phase8b architecture: importHtml authorizes and refuses AI origins BEFORE converting, reuses the package plan/commit, and audits only after the commit', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Application/StudioApplicationService.php');
    $start = strpos($src, 'public function importHtml(');
    $body = substr($src, $start, strpos($src, 'private function commitImportPlan(', $start) - $start);
    $auth = strpos($body, '$this->authorize($actor, StudioPermissions::EDIT)');
    $kind = strpos($body, '$this->importKind($actor)');
    $convert = strpos($body, '->convert(');
    assert_true($auth !== false && $kind !== false && $convert !== false && $auth < $convert && $kind < $convert, 'authorization + AI refusal precede parsing');
    assert_true(str_contains($body, '$packages->plan(') && str_contains($body, '$this->commitImportPlan('), 'the 8A plan/commit lifecycle');
    assert_true(strpos($body, 'if ($dryRun || $report->hasErrors())') < strpos($body, '$this->commitImportPlan('), 'a dry run never reaches the commit');
    assert_true(!str_contains($body, 'beginTransaction') && !str_contains($body, 'audit(') && !str_contains($body, '->publish('), 'no second transaction / audit / publish path');
    $commit = substr($src, strpos($src, 'private function commitImportPlan('), 4000);
    assert_true(strpos($commit, "'studio.package.imported'") > strpos($commit, '$pdo->commit()'));
    assert_true(!str_contains(substr($commit, strpos($commit, 'beginTransaction()'), strpos($commit, '$pdo->commit()') - strpos($commit, 'beginTransaction()')), 'audit('));
    foreach ([SLATE_ROOT . '/plugins/studio-builder/StudioBuilderMcpHandler.php', SLATE_ROOT . '/src/Module/StudioBuilder/Mcp/StudioMcpAdapter.php', SLATE_ROOT . '/src/Module/StudioBuilder/Mcp/StudioMcpToolCatalog.php'] as $f) {
        assert_true(!str_contains((string) file_get_contents($f), 'importHtml'), basename($f) . ' exposes no HTML import tool');
    }
});

if (!empty($studioP8bUnitStandalone)) {
    exit(unit_summary());
}
