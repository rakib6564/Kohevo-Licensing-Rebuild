<?php
// The capture and what reads it, for site.php and the redesigned pages.
declare(strict_types=1);

$BUNDLE = dirname(__DIR__);
$CAP = json_decode((string) file_get_contents("{$BUNDLE}/data/capture.json"), true);

// ── Helpers on the capture ─────────────────────────────────────────────────────
$MAIN = static function (string $u) use ($CAP): array {
    foreach (['d', 'm'] as $v) {
        foreach ($CAP[$u][$v]['parts'] as $p) {
            if (!in_array($p['type'], ['header', 'footer', 'jet-listing-items', 'popup'], true) && $p['tops'] !== []) { $out[$v] = $p['tops']; break; }
        }
    }
    return $out + ['d' => [], 'm' => []];
};
$meta = static fn(string $u): string => (string) ($CAP[$u]['d']['meta'] ?? '');
$PTITLE = static fn(string $u): string => trim(explode('|', (string) ($CAP[$u]['d']['title'] ?? ''))[0]);
function collect(array $n, string $t, array &$o): void { if ($n['k'] === 'w' && $n['t'] === $t) { $o[] = $n; } foreach ($n['kids'] ?? [] as $k) { collect($k, $t, $o); } }
$WP = [];
foreach (['pages', 'blog-post', 'recipe', 'service'] as $f) { foreach (json_decode((string) file_get_contents("{$BUNDLE}/data/{$f}.json"), true) as $x) { $WP[parse_url($x['link'], PHP_URL_PATH)] = $x; } }
$itemMeta = static function (string $u) use ($CAP): array {
    $out = [];
    foreach ($CAP[$u]['d']['parts'] as $p) {
        if ($p['type'] === 'jet-listing-items') { foreach ($p['tops'] as $t) { $w = []; collect($t, 'jet-listing-dynamic-field', $w); $line = trim(implode(' ', array_map(static fn($x) => trim((string) ($x['text'] ?? '')), $w))); if ($line !== '') { $out[] = $line; } } }
    }
    return $out;
};
$heroFile = static fn(array $top): string => (string) ($top['b']['bgf'] ?? '');

/** A Single template's hero, from the capture: the site's look, with the texts and the image read from the item. */
function HERO(array $top, array $mtop, array $map): array
{
    $kids = [];
    $w = [];
    collect($top, 'heading', $w);
    $t2 = [];
    collect($top, 'text-editor', $t2);
    foreach ([...$w, ...$t2] as $n) {
        $style = typo($n['ty'] ?? null);
        if (($n['tag'] ?? '') === 'h1') {
            $kids[] = B('theme.post_title', ['level' => 'h1'], [], $style);
        } elseif (isset($map[$n['tag'] ?? 'text'])) {
            $kids[] = B('theme.field_value', ['field' => $map[$n['tag'] ?? 'text'], 'tag' => 'div'], [], $style);
        }
    }
    $img = B('theme.field_value', ['field' => 'hero_image', 'tag' => 'div'], [], ['position' => ['mode' => 'absolute', 'top' => '0px', 'left' => '0px'], 'dimensions' => ['width' => '100%', 'height' => '100%']], ['classNames' => ['cg-hero-img']]);
    $shade = B('layout.flex', ['direction' => 'column', 'gap' => 'none'], [], ['position' => ['mode' => 'absolute', 'top' => '0px', 'left' => '0px'], 'dimensions' => ['width' => '100%', 'height' => '100%'], 'background' => ['color' => col($top['ov']['bg'] ?? null, (float) ($top['ov']['op'] ?? 1)) ?? 'rgba(0,0,0,0.6)']]);
    $text = B('layout.flex', ['direction' => 'column', 'gap' => 'none'], $kids, ['position' => ['mode' => 'relative'], 'dimensions' => ['max_width' => '1300px', 'width' => '100%'], 'layout' => ['gap' => '14px']]);
    $b0 = $top['b']; foreach ($top['ipad'] ?? [] as $i => $v) { $b0['pad'][$i] = (n($b0['pad'][$i]) + n($v)) . 'px'; } $p = box($b0['pad']);
    return CTOP('Hero', [$img, $shade, $text], ['position' => ['mode' => 'relative'], 'dimensions' => ['min_height' => max(300, (int) $top['b']['h']) . 'px', 'overflow' => 'hidden'], 'padding' => $p, 'layout' => ['display' => 'flex', 'direction' => 'column', 'justify' => 'end', 'align' => 'center']], ['mobile' => ['style' => ['padding' => box($mtop['b']['pad'] ?? [])]]]);
}
function SINGLE(string $slug, string $title, string $type, array $containers): int
{
    return build_page($slug, $title, 'system', 'standalone', $containers, ['settings' => ['template_type' => 'single', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => $type]]]]]);
}
/** A Loop Item: the card every Query Loop of this content type draws, listed under Templates. */
function LOOPITEM(string $slug, string $title, string $type, string $more): int
{
    global $runtime, $actor;
    $card = CTOP('Card', [
        B('theme.post_image', [], [], ['dimensions' => ['height' => '260px', 'width' => '100%', 'overflow' => 'hidden']], ['classNames' => ['cg-card-img']]),
        B('theme.post_title', ['level' => 'h3', 'link_to_post' => true], [], ['color' => INK, 'margin' => ['top' => '6px'], 'typography' => ['size' => '30px', 'font_family' => DISPLAY, 'weight' => '400', 'line_height' => '1.2']]),
        B('theme.post_excerpt', [], [], ['color' => COCOA, 'typography' => ['size' => '16px', 'font_family' => BODY, 'line_height' => '1.55']]),
        B('theme.post_link', ['text' => $more], [], ['color' => EMBER, 'typography' => ['size' => '15px', 'font_family' => BODY, 'weight' => '600']]),
    ], ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '10px', 'content_width' => 'full', 'padding_y' => 'none']]);
    $id = build_page($slug, $title, 'system', 'standalone', [$card], ['settings' => ['template_type' => 'loop', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => $type]]]]]);
    return $id;
}
function POSTCONTENT(): array { return CTOP('Item content', [B('theme.post_content', [])], ['layout' => ['display' => 'flex', 'direction' => 'column']]); }
function FV(string $key, array $style = []): array { return B('theme.field_value', ['field' => $key, 'tag' => 'div'], [], $style); }
