<?php
// Chef Gregory's in Kohevo Studio: real Studio blocks and content types, made from a capture of the live site's structure.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require_once getcwd() . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

set_exception_handler(static function (\Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n"); exit(1); });
$tid = current_tenant_id();
Database::query("UPDATE plugins SET status = 'inactive' WHERE slug IN ('coaching', 'multilang-translate')");
PluginLoader::activePlugins();
$runtime = StudioRuntimeFactory::build();
$adminId = (int) Database::value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND u.status = 'active' ORDER BY r.is_system DESC, u.id ASC LIMIT 1", [$tid]);
$actor = StudioActor::authenticated($adminId, StudioPermissions::ALL, true);
$defs = [];
foreach ($runtime->registry->editorManifests() as $m) { $defs[$m['type']] = $m; }

const SERIF = 'Cormorant, serif';
const SANS = 'Rajdhani, sans-serif';
const MEDIA_DIR = '/uploads/media/chef/';

function say(string $s): void { echo $s . "\n"; }

function B(string $type, array $props = [], array $children = [], array $style = [], array $opt = []): array
{
    global $defs;
    if (!isset($defs[$type])) { throw new RuntimeException("No block type {$type}"); }
    $d = $defs[$type];
    $b = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => (int) ($d['version'] ?? 1),
        'props' => array_replace((array) ($d['default_props'] ?? []), $props),
        'style' => $style + ['align' => ['base' => 'left']],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => array_values($children),
    ];
    // A device style cannot move a block, so a placed badge drops back into the flow on a phone (gap.css).
    if (($style['position']['mode'] ?? '') === 'absolute' && !isset($style['dimensions']['height'])) { $opt['classNames'][] = 'cg-abs'; }
    foreach (['responsive', 'style_parts', 'classNames', 'style_states'] as $k) { if (!empty($opt[$k])) { $b[$k] = $opt[$k]; } }
    return $b;
}

function fname(string $u): string { return trim((string) preg_replace('/[^a-z0-9.]+/', '-', strtolower(explode('/wp-content/uploads/', explode('?', $u)[0])[1] ?? basename($u))), '-'); }
function MID(string $file): int { global $tid; return (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, MEDIA_DIR . $file]); }
function IMGREF(string $file, string $alt = ''): ?array { $id = $file !== '' ? MID($file) : 0; return $id > 0 ? ['media_id' => $id, 'alt' => $alt, 'focal_point' => [0.5, 0.5]] : null; }

/** A computed colour as a style value; null for transparent. */
function col(?string $c, float $alpha = 1.0): ?string
{
    if ($c === null || !preg_match('/rgba?\(([\d.]+), ([\d.]+), ([\d.]+)(?:, ([\d.]+))?\)/', $c, $m)) { return null; }
    $a = (isset($m[4]) ? (float) $m[4] : 1.0) * $alpha;
    if ($a <= 0) { return null; }
    return $a >= 1 ? sprintf('#%02x%02x%02x', $m[1], $m[2], $m[3]) : sprintf('rgba(%d,%d,%d,%s)', $m[1], $m[2], $m[3], rtrim(rtrim(number_format($a, 2), '0'), '.'));
}
function n(?string $px): float { return (float) $px; }
function len(?string $px): ?string { $v = round(n($px)); return $v != 0 ? $v . 'px' : null; }
function box(array $v, bool $negative = false): array
{
    $out = [];
    foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
        $l = len($v[$i] ?? null);
        if ($l !== null && ($negative || $l[0] !== '-')) { $out[$side] = $l; }
    }
    return $out;
}
function fam(string $ff): string
{
    $first = trim(explode(',', $ff)[0], " \"'");
    return match (true) {
        in_array($first, ['Cormorant', 'Cormorant Garamond', 'Italianno', 'Playfair Display', 'Lora'], true) => $first . ', serif',
        $first === '' => SANS,
        default => $first . ', sans-serif',
    };
}
function typo(?array $t, ?array $m = null): array
{
    if (!$t) { return []; }
    $ty = array_filter([
        'size' => round(n($t['fs'])) . 'px', 'weight' => $t['fw'], 'font_family' => fam($t['ff']),
        'line_height' => n($t['lh']) > 0 ? (string) round(n($t['lh']) / max(1, n($t['fs'])), 2) : null,
        'letter_spacing' => n($t['ls']) != 0 ? round(n($t['ls']), 1) . 'px' : null,
        'transform' => $t['tt'] !== 'none' ? $t['tt'] : null,
        'style' => $t['fst'] === 'italic' ? 'italic' : null,
    ], static fn($x) => $x !== null);
    $s = ['typography' => $ty];
    if (($c = col($t['color'])) !== null) { $s['color'] = $c; }
    if (in_array($t['ta'], ['center', 'right'], true)) { $s['align'] = ['base' => $t['ta']]; }
    return $s;
}
function mobile_type(?array $t, ?array $m): array
{
    return $t && $m && $t['fs'] !== $m['fs'] ? ['typography' => ['size' => round(n($m['fs'])) . 'px']] : [];
}
function part(array $s): array
{
    unset($s['align']);
    if (isset($s['typography'])) {
        $t = &$s['typography'];
        if (isset($t['font_family'])) { $t['family'] = str_starts_with($t['font_family'], 'Cormorant') ? 'heading' : 'body'; unset($t['font_family']); }
        $t['weight'] = ['400' => 'normal', '500' => 'medium', '600' => 'semibold', '700' => 'bold', '800' => 'extrabold'][$t['weight'] ?? ''] ?? null;
        if ($t['weight'] === null) { unset($t['weight']); }
        if (isset($t['line_height'], $t['size'])) { $t['line_height'] = round((float) $t['size'] * (float) $t['line_height'], 1) . 'px'; } else { unset($t['line_height']); }
    }
    return $s;
}
/** What a box itself paints: padding, background, border, radius, shadow. */
function surface(array $b, ?array $ov = null): array
{
    $s = [];
    if ($p = box($b['pad'] ?? [])) { $s['padding'] = $p; }
    $bg = array_filter(['color' => col($b['bg'] ?? null)]);
    if (!empty($b['bgf']) && MID($b['bgf']) > 0) {
        $bg['image'] = IMGREF($b['bgf']);
        $bg['fit'] = ($b['bgs'] ?? '') === 'contain' ? 'contain' : (str_contains((string) ($b['bgs'] ?? ''), 'cover') ? 'cover' : 'auto');
        $bg['repeat'] = ($bg['fit'] === 'auto') ? 'repeat' : 'no-repeat';
        $bg['position'] = 'center';
        if ($ov && ($c = col($ov['bg'] ?? null, (float) ($ov['op'] ?? 1))) !== null) { $bg['overlay'] = ['color' => $c]; }
    }
    if ($bg) { $s['background'] = $bg; }
    $bw = $b['bw'] ?? [];
    if (($b['bs'] ?? 'none') !== 'none' && n($bw[0] ?? '0') > 0 && n($bw[1] ?? '0') > 0) {
        $s['border'] = ['width' => round(n($bw[0])) . 'px', 'style' => 'solid', 'color' => col($b['bc']) ?? '#000000'];
    } elseif (n($bw[3] ?? '0') > 0 && ($c = col($b['blc'] ?? null)) !== null) {
        $s['border'] = ['left' => ['width' => round(n($bw[3])) . 'px', 'style' => 'solid', 'color' => $c]];
    } elseif (n($bw[2] ?? '0') > 0 && ($c = col($b['bbc'] ?? null)) !== null) {
        $s['border'] = ['bottom' => ['width' => round(n($bw[2])) . 'px', 'style' => 'solid', 'color' => $c]];
    }
    if (n($b['rad'] ?? '0') > 0) { $s['border']['radius'] = round(n($b['rad'])) . 'px'; }
    if (preg_match('/^(rgba?\([^)]+\)) (-?\d+)px (-?\d+)px (\d+)px (-?\d+)px/', (string) ($b['shadow'] ?? ''), $m)) {
        $s['shadow'] = ['x' => $m[2] . 'px', 'y' => $m[3] . 'px', 'blur' => $m[4] . 'px', 'spread' => $m[5] . 'px', 'color' => col($m[1]) ?? '#000000'];
    }
    return $s;
}
/** An absolutely placed box keeps its place. */
function placed(array $b): array
{
    if (($b['pos'] ?? '') !== 'absolute') { return []; }
    $at = ['mode' => 'absolute'];
    foreach (['top', 'left'] as $k) { if (isset($b[$k]) && str_ends_with((string) $b[$k], 'px')) { $at[$k] = round(n($b[$k])) . 'px'; } }
    return ['position' => $at];
}
function clean_html(string $h): string
{
    $h = (string) preg_replace('~<(style|script)\b.*?</\1>~si', '', $h);
    $h = strip_tags($h, '<p><ul><ol><li><a><strong><em><b><i><br><h2><h3><h4>');
    $h = (string) preg_replace_callback('~<a\b[^>]*>~i', static function (array $m): string {
        return preg_match('~href="([^"]+)"~i', $m[0], $x) ? '<a href="' . htmlspecialchars(local($x[1]), ENT_QUOTES) . '">' : '<a>';
    }, $h);
    $h = (string) preg_replace('~<(p|ul|ol|li|strong|em|b|i|h[234])\b[^>]*>~i', '<$1>', $h);
    return trim((string) preg_replace('~\s+~', ' ', $h));
}
function plain(string $h): string { return trim((string) preg_replace('~\s+~', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', $h))))); }
function local(string $u): string
{
    $u = trim($u);
    return preg_match('~^https?://(www\.)?chefgregory\.com(/.*)?$~', $u, $m) ? (($m[2] ?? '') ?: '/') : ($u === '' ? '#' : $u);
}
function L(string $label, string $href): array { $h = local($href); return ['label' => $label, 'href' => $h, 'target' => str_starts_with($h, 'http') ? '_blank' : '_self']; }

/** Query Loop cards drawn like the live site's listings. */
function LOOP(string $type, int $cols, int $per, bool $date = true): array
{
    $card = ['background' => ['color' => '#ffffff'], 'padding' => ['top' => '0px', 'right' => '0px', 'bottom' => '24px', 'left' => '0px']];
    return B('core.query_loop', ['content_type' => $type, 'columns' => max(1, min(6, $cols ?: 3)), 'per_page' => $per, 'gap' => 'lg', 'order_by' => 'published_at', 'order' => 'desc', 'show_author' => false, 'show_category' => false, 'show_date' => $date, 'read_more_text' => 'READ MORE', 'enable_pagination' => false], [], [], ['style_parts' => [
        'card' => $card,
        'title' => part(['typography' => ['family' => 'x', 'size' => '26px', 'font_family' => SERIF, 'weight' => '500'], 'color' => '#1c1b1f']),
        'excerpt' => ['color' => '#585858'],
        'button' => ['background' => ['color' => '#1c1b1f'], 'color' => '#ffffff'],
    ], 'responsive' => ['mobile' => ['props' => ['columns' => 1]]]]);
}
function listing_type(array $n): string
{
    $t = (string) ($n['text'] ?? '');
    return match (true) {
        str_contains($t, 'SERVICE AR') || str_contains($t, 'Service Ar') => 'service',
        str_contains($t, 'Cook Time') => 'recipe',
        (bool) preg_match('/^[A-Z][a-z]+ \d{1,2}, 20\d\d/', $t) => 'blog_post',
        default => '',
    };
}

/** One captured widget as blocks. `$m` is the same widget at phone width. */
function widget(array $n, ?array $m): array
{
    $b = $n['b'];
    $sf = surface($b);
    $mar = box($b['mar'] ?? [], true);
    $base = $sf + ($mar ? ['margin' => $mar] : []) + placed($b);
    $rm = static fn(array $s): array => $s ? ['responsive' => ['mobile' => ['style' => $s]]] : [];
    switch ($n['t']) {
        case 'heading':
            $text = trim((string) preg_replace('/\s+/', ' ', (string) ($n['text'] ?? '')));
            if ($text === '') { return []; }
            $style = typo($n['ty'] ?? null) + $base;
            $mob = mobile_type($n['ty'] ?? null, $m['ty'] ?? null);
            if (mb_strlen($text) > 280) { return [B('core.rich_text', ['content' => '<p>' . htmlspecialchars($text) . '</p>'], [], $style, $rm($mob))]; }
            if (in_array($n['tag'], ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $props = ['text' => $text, 'level' => $n['tag']];
                if (!empty($n['href'])) { $props['link'] = L($text, $n['href']); }
                return [B('core.heading', $props, [], $style, $rm($mob))];
            }
            return [B('core.text', ['content' => $text, 'size' => 'inherit'], [], $style, $rm($mob))];
        case 'text-editor':
            $html = clean_html((string) ($n['html'] ?? ''));
            if (plain($html) === '') { return []; }
            return [B('core.rich_text', ['content' => $html], [], typo($n['ty'] ?? null) + $base, $rm(mobile_type($n['ty'] ?? null, $m['ty'] ?? null)))];
        case 'image':
            $ref = IMGREF((string) ($n['file'] ?? ''), (string) ($n['alt'] ?? ''));
            if ($ref === null) { return []; }
            $st = $base + ['dimensions' => array_filter(['width' => ($n['iw'] ?? 0) ? min(100, (int) round($n['iw'] / max(1, $b['w']) * 100)) . '%' : null, 'height' => ($n['fit'] ?? '') === 'cover' && ($n['ih'] ?? 0) ? $n['ih'] . 'px' : null, 'object_fit' => ($n['fit'] ?? '') === 'cover' ? 'cover' : null])];
            return [B('core.image', ['media' => $ref, 'aspect_ratio' => 'auto', 'rounded' => false], [], $st)];
        case 'button':
            $text = trim((string) preg_replace('/\s+/', ' ', (string) ($n['text'] ?? '')));
            if ($text === '') { return []; }
            $look = part(surface($n['bt'] ?? []) + typo($n['ty'] ?? null));
            $look['background']['color'] ??= 'rgba(0,0,0,0)';
            $look['border']['radius'] ??= '0px';
            return [B('core.button', ['link' => L($text, (string) ($n['href'] ?? '#')), 'variant' => 'primary'], [], $base + (isset($n['ty']['ta']) && $n['ty']['ta'] === 'center' ? [] : []), ['style_parts' => ['button' => $look]])];
        case 'icon-list':
            $items = $n['items'] ?? [];
            if (!$items) { return []; }
            if (array_filter($items, static fn(array $i): bool => ($i['href'] ?? '') !== '')) {
                $links = [];
                foreach ($items as $i) {
                    $links[] = ($i['href'] ?? '') !== ''
                        ? B('core.link', ['link' => L(trim($i['text']), $i['href']), 'style' => 'plain'], [], typo($n['ty'] ?? null))
                        : B('core.text', ['content' => trim($i['text']), 'size' => 'inherit'], [], typo($n['ty'] ?? null));
                }
                return [B('layout.flex', ['direction' => str_contains((string) $n['cls'], 'layout-inline') ? 'row' : 'column', 'gap' => 'none', 'wrap' => 'wrap', 'align' => 'start'], $links, $base + ['layout' => ['gap' => '8px', 'direction' => str_contains((string) $n['cls'], 'layout-inline') ? 'row' : 'column']])];
            }
            $check = (bool) array_filter($items, static fn(array $i): bool => str_contains((string) ($i['icon'] ?? ''), 'check'));
            return [B('core.list', ['style' => $check ? 'check' : 'none', 'items' => array_map(static fn(array $i): array => ['text' => mb_substr(trim($i['text']), 0, 300)], array_slice($items, 0, 30))], [], typo($n['ty'] ?? null) + $base, ['style_parts' => array_filter(['marker' => ($c = col($items[0]['icolor'] ?? null)) ? ['color' => $c] : null])])];
        case 'icon':
            $ic = (string) ($n['icon'] ?? '');
            $name = match (true) { str_contains($ic, 'star') => 'star', str_contains($ic, 'phone') => 'phone', str_contains($ic, 'envelope') => 'mail', str_contains($ic, 'map') => 'map-pin', str_contains($ic, 'check') => 'check', default => 'star' };
            return [B('core.icon', ['name' => $name, 'size_px' => max(12, min(80, (int) round(n($n['ty']['fs'] ?? '16px'))))] + (!empty($n['href']) ? ['url' => local((string) $n['href'])] : []), [], ['color' => col($n['ty']['color'] ?? null) ?? '#ff7f00'] + $base)];
        case 'counter':
            return [B('core.stats', ['items' => [['value' => (float) $n['num'], 'label' => trim((string) $n['title']), 'prefix' => (string) $n['pre'], 'suffix' => (string) $n['suf'], 'decimals' => 0]]], [], $base, ['style_parts' => array_filter(['number' => part(typo($n['ty'] ?? null)), 'label' => part(typo($n['tty'] ?? null))])])];
        case 'accordion':
            $rows = array_map(static fn(array $i): array => ['heading' => mb_substr($i['title'], 0, 200), 'body' => mb_substr(plain($i['html']), 0, 2000)], array_slice($n['items'] ?? [], 0, 20));
            return $rows ? [B('core.accordion', ['items' => $rows], [], $base)] : [];
        case 'gallery': case 'image-gallery': case 'image-carousel':
            $imgs = [];
            foreach ($n['files'] ?? [] as $f) { if (MID($f) > 0) { $imgs[] = ['url' => MEDIA_DIR . $f, 'alt' => '']; } }
            return $imgs ? [B('core.gallery', ['images' => array_slice($imgs, 0, 50), 'columns' => in_array((string) ($n['cols'] ?? ''), ['2', '3', '4', '6'], true) ? (string) $n['cols'] : '4', 'aspect_ratio' => '4:3'], [], $base)] : [];
        case 'form':
            $fields = [];
            foreach ($n['fields'] ?? [] as $f) {
                $type = in_array($f['type'], ['text', 'email', 'tel', 'textarea', 'select', 'number', 'date'], true) ? $f['type'] : ($f['type'] === 'select-one' ? 'select' : 'text');
                $fields[] = B('core.form_field', ['field_type' => $type, 'name' => preg_replace('/[^a-z0-9_]/', '_', strtolower($f['label'] ?: $f['name'])) ?: 'field', 'label' => $f['label'] ?: $f['ph'], 'placeholder' => $f['ph'], 'required' => $f['req']] + ($f['opts'] ? ['options' => implode(', ', $f['opts'])] : []));
            }
            return [B('core.form', ['form_name' => 'Contact', 'submit_text' => $n['submit'] ?: 'Send', 'submit_variant' => 'primary', 'success_message' => 'Thank you. We respond within 24 hours.'], $fields, $base)];
        case 'video':
            return preg_match('~src="(https?://[^"]+)"~', (string) ($n['html'] ?? ''), $v) ? [B('core.video', ['source' => 'hosted', 'hosted_url' => $v[1]], [], $base)] : [];
        case 'jet-listing-grid': case 'loop-grid':
            $type = listing_type($n);
            return $type !== '' ? [LOOP($type, ((int) ($n['cols'] ?? 0)) > 1 ? (int) $n['cols'] : 3, max(1, min(24, (int) ($n['items'] ?? 6))), $type === 'blog_post')] : [];
        default:
            return [];
    }
}

/** One captured container as a block; a plain wrapper around one child is dropped. */
function con(array $n, ?array $m, float $parentW = 0, bool $inRow = false): array
{
    $kids = [];
    // An older Elementor section or column is not a flex row of its own: where its columns sit says what it is.
    $old = $n['disp'] === 'block' || str_contains((string) $n['cls'], 'elementor-column') || str_contains((string) $n['cls'], 'elementor-section');
    if ($old) {
        $k0 = $n['kids'][0]['b']['x'] ?? 0;
        $k1 = $n['kids'][1]['b']['x'] ?? $k0;
        $n['dir'] = $k1 > $k0 + 20 ? 'row' : 'column';
        $n['disp'] = 'flex';
    }
    $row = str_starts_with($n['dir'], 'row') && $n['disp'] !== 'grid';
    foreach ($n['kids'] as $i => $k) {
        $mk = $m['kids'][$i] ?? null;
        $inner = (float) ($n['iw'] ?? $n['b']['w']);
        array_push($kids, ...($k['k'] === 'c' ? [con($k, $mk, $inner, $row)] : widget($k, $mk)));
    }
    $kids = array_values(array_filter($kids));
    $b0 = $n['b'];
    foreach ($n['ipad'] ?? [] as $i => $v) { $b0['pad'][$i] = (n($b0['pad'][$i] ?? '0') + n($v)) . 'px'; }
    $sf = surface($b0, $n['ov'] ?? null);
    if (!$kids && !$sf) { return []; }
    $gap = n($n['gap']);
    $layout = ['display' => 'flex', 'direction' => $row ? 'row' : 'column', 'wrap' => $n['wrap'] === 'wrap' ? 'wrap' : 'nowrap'];
    if ($gap > 0) { $layout['gap'] = round($gap) . 'px'; }
    $jmap = ['center' => 'center', 'flex-end' => 'end', 'space-between' => 'between', 'space-around' => 'around', 'space-evenly' => 'evenly'];
    $amap = ['center' => 'center', 'flex-end' => 'end'];
    if (isset($jmap[$n['jc']])) { $layout['justify'] = $jmap[$n['jc']]; }
    if (isset($amap[$n['ai']])) { $layout['align'] = $amap[$n['ai']]; }
    $grid = $n['disp'] === 'grid';
    $style = $sf + placed($n['b']);
    if (str_ends_with($n['dir'], '-reverse')) { $layout['direction'] = $n['dir']; }
    if ($grid) {
        $cols = count(explode(' ', $n['grid']));
        $layout = ['display' => 'grid', 'columns' => min(12, max(1, $cols))] + ($gap > 0 ? ['gap' => round($gap) . 'px'] : []);
    }
    $style['layout'] = $layout;
    if ($inRow && $parentW > 0) { $style['dimensions']['width'] = min(100, round($n['b']['w'] / $parentW * 100, 1)) . '%'; }
    $style['dimensions']['max_width'] = '100%';
    if (n($n['b']['minh'] ?? '0') > 40) { $style['dimensions']['min_height'] = round(n($n['b']['minh'])) . 'px'; }
    $mob = [];
    if ($m) {
        $bm = $m['b'];
        foreach ($m['ipad'] ?? [] as $i => $v) { $bm['pad'][$i] = (n($bm['pad'][$i] ?? '0') + n($v)) . 'px'; }
        if ($p = box($bm['pad'] ?? [])) { $mob['padding'] = $p; }
        // Elementor keeps a stacked row as a wrapping row of full-width children; here it simply becomes a column.
        $mw = (float) ($m['iw'] ?? $m['b']['w']);
        $full = (bool) array_filter($m['kids'] ?? [], static fn(array $k): bool => $k['k'] === 'c' && $k['b']['w'] >= 0.8 * ($mw - n($m['b']['pad'][1] ?? '0') - n($m['b']['pad'][3] ?? '0')));
        if ($row && (str_starts_with($m['dir'], 'column') || $full)) { $mob['layout']['direction'] = 'column'; }
        if ($grid && $m['disp'] === 'grid') { $mob['layout']['columns'] = max(1, count(explode(' ', $m['grid']))); }
        if ($inRow) { $mob['dimensions']['width'] = '100%'; }
    }
    $opt = $mob ? ['responsive' => ['mobile' => ['style' => $mob]]] : [];
    if (str_contains((string) $n['cls'], 'hover-cta')) { $opt['classNames'] = ['cg-hover']; }
    return B('layout.flex', ['direction' => $row ? 'row' : 'column', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], $kids, $style, $opt);
}

/** A captured top-level section as a full-width Container, boxed to its inner width. */
function top(array $n, ?array $m, string $label = ''): array
{
    if ($n['k'] === 'w') {
        $kids = widget($n, $m);
        $n = ['k' => 'c', 'b' => $n['b'], 'dir' => 'column', 'disp' => 'flex', 'wrap' => 'nowrap', 'gap' => '0px', 'jc' => 'normal', 'ai' => 'normal', 'grid' => 'none', 'kids' => []];
        return $kids ? CTOP($label, $kids, []) : [];
    }
    $b = con($n, $m);
    if (!$b) { return []; }
    $style = $b['style'];
    unset($style['align']);
    if (($t = len($n['b']['mar'][0] ?? null)) !== null && $t[0] !== '-') { $style['margin']['top'] = $t; }
    $iw = (int) ($n['iw'] ?? 0);
    if ($iw > 0 && $iw < 1400) { $style['layout']['content_width'] = 'boxed'; $style['layout']['content_max'] = $iw . 'px'; }
    return CTOP($label ?: mb_substr(first_text($n) ?: 'Section', 0, 60), $b['children'], $style, $b['responsive'] ?? []);
}
function CTOP(string $label, array $children, array $style, array $responsive = []): array
{
    $c = CanonicalDocumentSchema::newContainer([
        'metadata' => ['label' => mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ,.&-]+/u', ' ', $label)) ?: 'Section', 0, 60)],
        'style' => $style,
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'children' => array_values($children),
    ]);
    if ($responsive) { $c['responsive'] = $responsive; }
    return $c;
}
function first_text(array $n): string
{
    if ($n['k'] === 'w') { return trim((string) ($n['text'] ?? plain((string) ($n['html'] ?? '')))); }
    foreach ($n['kids'] as $k) { if (($t = first_text($k)) !== '') { return $t; } }
    return '';
}

function check_doc(array $doc): void
{
    global $runtime;
    try {
        \Slate\Module\StudioBuilder\Document\ValidatedDocument::from($doc, $runtime->registry, ['allow_locked' => true]);
    } catch (\Slate\Module\StudioBuilder\Exception\StudioValidationException $e) {
        foreach (array_slice($e->errors(), 0, 12) as $i) { say('   ! ' . ($i['path'] ?? '') . ' ' . ($i['code'] ?? '') . ': ' . ($i['message'] ?? '')); }
        throw new RuntimeException('invalid document');
    }
}

/** Create or replace a page and publish it. `$type`: the content type of an item. */
function build_page(string $slug, string $title, string $pageType, string $route, array $containers, array $opt = []): int
{
    global $runtime, $actor, $tid;
    $doc = CanonicalDocumentSchema::emptyDocument($pageType, 'default', ($opt['seo_title'] ?? '') ?: $title);
    $doc['containers'] = array_values(array_filter($containers));
    $doc['settings'] = array_replace($doc['settings'], $opt['settings'] ?? []);
    if ($pageType === 'system') { $doc['seo']['robots'] = 'noindex,nofollow'; }
    if (!empty($opt['seo_desc'])) { $doc['seo']['description'] = mb_substr((string) $opt['seo_desc'], 0, 300); }
    if (!empty($opt['image']) && ($id = MID($opt['image'])) > 0) { $doc['seo']['og_image_media_id'] = $id; }
    check_doc($doc);
    $old = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = ? AND status <> 'archived'", [$tid, $slug, $pageType]);
    if ($old) {
        $pageId = (int) $old['id'];
        Database::query('UPDATE studiobuilder_pages SET title = ? WHERE id = ?', [$title, $pageId]);
        $head = (int) Database::value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE id = ?', [$pageId]);
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, $head, 'manual', 'Chef Gregory site');
    } else {
        $created = $runtime->app->createPage($actor, $title, $slug, $pageType, $route);
        $pageId = (int) $created['page']['id'];
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id'], 'manual', 'Chef Gregory site');
    }
    if (!empty($opt['type'])) {
        $r = \Slate\Module\StudioBuilder\Meta\PageMeta::save($tid, $pageId, (string) $opt['type'], $opt['fields'] ?? []);
        if ($r['errors']) { throw new RuntimeException($slug . ': ' . json_encode($r['errors'])); }
    }
    $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'Chef Gregory site');
    if (!empty($opt['date'])) { $runtime->app->setPublishDate($actor, $pageId, date('Y-m-d H:i:s', strtotime((string) $opt['date']))); }
    say("  + {$pageType} /{$slug} (#{$pageId})");
    return $pageId;
}
