<?php
/**
 * Solaya — build the complete Studio site: header, footer, seven pages, three journal articles and the
 * single, archive and 404 templates, together using every native Studio block.
 *
 * Copy comes only from what bin/seed-solaya.php records about the practice (the practitioner, the five
 * services and their booking rules, the Body & Soul programme). Nothing the requirements leave open is
 * invented: no prices, address, testimonials, dates or video. The blocks that need one of those (countdown,
 * map, video, payment button, payment link) are built but shown to signed-in staff only, and listed at the
 * end for a human to fill in and then make public.
 *
 * Run bin/seed-solaya.php first (services, programme). Then:
 *   php bin/install-solaya-site.php            dry run: what would be removed and built
 *   php bin/install-solaya-site.php --apply    remove the current Studio site and build this one
 *
 * "Remove" is reversible: each Studio page is renamed `archived-<date>-<id>-<slug>` (so its address is free)
 * and archived, so it can be restored from Studio. Studio templates are deleted, except the built-in Library sections. Media, forms submissions,
 * bookings and membership data are never touched. Back up the database before --apply on a live site.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/install-solaya-site.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Media\Media;

$APPLY = in_array('--apply', array_slice($argv, 1), true);
$tid = current_tenant_id();
PluginLoader::activePlugins();
$runtime = StudioRuntimeFactory::build();
$adminId = (int) Database::value(
    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND u.status = 'active' ORDER BY r.is_system DESC, u.id ASC LIMIT 1",
    [$tid]
);
if ($adminId <= 0) {
    fwrite(STDERR, "No active admin user in tenant {$tid}.\n");
    exit(1);
}
$actor = StudioActor::authenticated($adminId, StudioPermissions::ALL, true);
$todo = [];

function say(string $s): void { echo $s . "\n"; }

say($APPLY ? "Building the Solaya site (tenant {$tid})\n" : "DRY RUN (tenant {$tid}): nothing is written. Add --apply to build.\n");

// ── Block definitions: every block starts from its own default props ─────────────────────────────────────────────
$defs = [];
foreach ($runtime->registry->editorManifests() as $m) {
    $defs[$m['type']] = $m;
}

/** A block of `$type`: its manifest defaults, overridden by `$props`; `$opt` sets children, style, visibility, bindings. */
function B(string $type, array $props = [], array $children = [], array $opt = []): array
{
    global $defs;
    if (!isset($defs[$type])) {
        throw new RuntimeException("Block type {$type} is not available on this install (is its plugin active?).");
    }
    $d = $defs[$type];
    $block = [
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'type'       => $type,
        'version'    => (int) ($d['version'] ?? 1),
        'props'      => array_replace((array) ($d['default_props'] ?? []), $props),
        'style'      => $opt['style'] ?? ['align' => ['base' => 'left']],
        'visibility' => $opt['visibility'] ?? ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings'   => $opt['bindings'] ?? [],
        'children'   => $children,
    ];
    foreach (['responsive', 'classNames', 'animation'] as $key) {
        if (isset($opt[$key])) {
            $block[$key] = $opt[$key];
        }
    }
    return $block;
}

/** A small tracked label above a heading (styled by `.sl-eyebrow` in solaya.css). */
function EYEBROW(string $text, bool $center = false): array
{
    return B('core.text', ['content' => $text, 'size' => 'sm', 'align' => $center ? 'center' : 'left'], [], ['classNames' => ['sl-eyebrow']]);
}

/** One numbered step: an italic serif numeral over a short heading and line. */
function STEP(string $num, string $heading, string $text): array
{
    return B('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
        B('core.text', ['content' => $num], [], ['classNames' => ['sl-step__num']]),
        B('core.heading', ['text' => $heading, 'level' => 'h3']),
        B('core.text', ['content' => $text]),
    ], ['classNames' => ['sl-step']]);
}

/** Two columns side by side from tablet width up, stacked on a phone (a two-column grid otherwise stays two columns everywhere). */
function GRID2(string $align, array $children): array
{
    return B('layout.grid', ['columns' => 2, 'gap' => 'lg', 'align' => $align], $children, [
        'responsive' => ['mobile' => ['style' => ['layout' => ['columns' => 1]]]],
    ]);
}

/**
 * A page section holding `$blocks`, one column wide: a section's blocks are equal-width columns up to its column count,
 * so a single column stacks them, and grids inside the section make the real columns.
 */
function SEC(string $label, array $blocks, string $width = 'wide', string $surface = 'surface.primary', array $padding = ['base' => 'md', 'md' => 'lg'], string $audience = 'any'): array
{
    $section = [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'global_ref' => null,
        'layout'     => ['background_token' => $surface, 'columns' => ['base' => 1, 'md' => 1], 'gap' => 'md', 'padding_y' => $padding, 'width' => $width],
        'visibility' => ['auth_state' => $audience, 'devices' => ['base', 'sm', 'md', 'lg']],
        'blocks'     => $blocks,
    ];
    if (!in_array($label, ['Header', 'Footer', 'Hero', 'Intro', 'About', 'Contact', 'Book', 'Journal', 'Article'], true)) {
        $section['animation'] = ['type' => 'fade_up'];
    }
    return $section;
}

function link_to(string $label, string $href): array { return ['label' => $label, 'href' => $href, 'target' => '_self']; }
function centered(): array { return ['style' => ['align' => ['base' => 'center']]]; }

/** Shown only to signed-in staff until a human supplies the missing fact (see the list at the end). */
function staff_only(): array { return ['visibility' => ['auth_state' => 'authenticated', 'devices' => ['base', 'sm', 'md', 'lg']]]; }


// ── 1. Images: soft generated artwork (no stock photos), registered in the media library ────────────────────────
// Under /uploads/media/: the image renderer only serves files from the media library's managed folders.
$artDir = SLATE_ROOT . '/uploads/media/solaya-site';
$art = [
    // name => [top colour, bottom colour, glow colour, portrait?, alt text]
    'hero-v2'      => [[241, 228, 212], [205, 160, 128], [255, 246, 232], true, 'Warm morning light over soft sand tones'],
    'about-v2'     => [[233, 236, 226], [143, 160, 132], [250, 248, 236], true, 'Calm sage-green light'],
    'programme-v2' => [[240, 229, 219], [170, 118, 98], [255, 241, 228], true, 'Soft terracotta light'],
    'nutrition-v2' => [[245, 236, 216], [190, 160, 96], [255, 248, 230], false, 'Golden light suggesting nourishment'],
    'hypnosis-v2'  => [[229, 228, 236], [128, 122, 158], [246, 242, 250], false, 'Quiet lavender light'],
    'journal-1-v2' => [[238, 232, 222], [168, 146, 120], [252, 247, 238], false, 'Neutral morning light'],
    'journal-2-v2' => [[231, 236, 229], [118, 146, 126], [246, 250, 242], false, 'Soft green light'],
    'journal-3-v2' => [[239, 230, 233], [158, 118, 134], [252, 244, 246], false, 'Soft rose light'],
];
$media = [];
if ($APPLY) {
    if (!is_dir($artDir) && !mkdir($artDir, 0755, true)) {
        throw new RuntimeException("Cannot create {$artDir}");
    }
    foreach ($art as $name => [$from, $to, $glow, $portrait]) {
        $file = "{$artDir}/{$name}.jpg";
        if (!is_file($file)) {
            solaya_paint($file, $from, $to, $glow, $portrait);
        }
    }
    Media::ensureSchema();
    Media::importFolder('media/solaya-site');
}
foreach ($art as $name => [, , , , $alt]) {
    $path = "/uploads/media/solaya-site/{$name}.jpg";
    $id = $APPLY ? (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, $path]) : 1;
    if ($APPLY && $id <= 0) {
        throw new RuntimeException("Image {$path} was not registered in the media library.");
    }
    $media[$name] = ['id' => $id, 'path' => $path, 'alt' => $alt];
}
say('  + images    ' . count($art) . ' generated in /uploads/media/solaya-site/');
$img = static fn(string $n): array => ['media_id' => $media[$n . '-v2']['id'], 'alt' => $media[$n . '-v2']['alt'], 'focal_point' => [0.5, 0.5]];
$imgUrl = static fn(string $n): string => (defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '') . $media[$n . '-v2']['path'];
$alt = static fn(string $n): string => $media[$n . '-v2']['alt'];

/** Layered soft light: a vertical wash, two drifting glows and a low sun, finished with fine grain, as a JPEG. */
function solaya_paint(string $file, array $from, array $to, array $glow, bool $portrait): void
{
    [$w, $h] = $portrait ? [1200, 1500] : [1600, 1000];
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = ($y / ($h - 1)) ** 1.15;
        $c = imagecolorallocate($im, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($im, 0, $y, $w, $y, $c);
    }
    imagealphablending($im, true);
    $soft = static function (int $cx, int $cy, int $radius, array $rgb, int $strength) use ($im): void {
        for ($r = $radius; $r > 0; $r -= 4) {
            $a = (int) max(0, min(127, 127 - $strength * (1 - $r / $radius) ** 2));
            imagefilledellipse($im, $cx, $cy, $r * 2, (int) ($r * 1.7), imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $a));
        }
    };
    $soft((int) ($w * 0.22), (int) ($h * 0.30), (int) ($w * 0.55), $glow, 40);
    $soft((int) ($w * 0.85), (int) ($h * 0.75), (int) ($w * 0.45), $to, 30);
    $soft((int) ($w * 0.62), (int) ($h * ($portrait ? 0.46 : 0.40)), (int) ($w * ($portrait ? 0.24 : 0.17)), [255, 250, 242], 110);
    mt_srand(crc32($file));
    for ($i = 0, $n = (int) ($w * $h * 0.06); $i < $n; $i++) {
        $v = mt_rand(0, 1) ? 255 : 0;
        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocatealpha($im, $v, $v, $v, 118));
    }
    imagejpeg($im, $file, 88);
    imagedestroy($im);
}

// ── Theme: tokens, fonts and the site stylesheet (replaces the previous ones; the database backup keeps them) ──────
$tokens = [
    'surface.page' => '#f6f1ea', 'surface.primary' => '#f6f1ea', 'surface.secondary' => '#ede4d8', 'surface.muted' => '#e6dbcd',
    'surface.accent' => '#9a6a4f', 'surface.inverse' => '#2f2620',
    'text.primary' => '#2a211c', 'text.muted' => '#74665c', 'text.accent' => '#9a6a4f', 'text.inverse' => '#f6f1ea',
    'color.accent' => '#9a6a4f', 'border.default' => '#ddd0c0',
    'font.heading' => '"Cormorant Garamond", Georgia, "Times New Roman", serif',
    'font.body' => 'Jost, "Helvetica Neue", Arial, sans-serif',
    'radius.sm' => '3px', 'radius.md' => '6px', 'radius.lg' => '10px', 'radius.full' => '999px',
    'shadow.sm' => '0 1px 2px rgba(42,33,28,0.05)', 'shadow.md' => '0 18px 40px rgba(42,33,28,0.08)',
];
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,300;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">';
// Comments are dropped here, so `changed` below means the cleaner removed real CSS.
$css = \Slate\Module\StudioBuilder\Http\StudioCodePolicy::prepareCustomCss(trim((string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(__DIR__ . '/solaya-site/solaya.css'))));
$head = \Slate\Module\StudioBuilder\Http\StudioCodePolicy::prepareSnippet($fonts, 'head');
if ($css['changed']) {
    throw new RuntimeException('The Solaya stylesheet lost something to the CSS cleaner; fix bin/solaya-site/solaya.css.');
}
if ($APPLY) {
    $runtime->app->saveDesignTokens($actor, 'default', $tokens);
    Database::setSetting(\Slate\Module\StudioBuilder\Http\StudioCodePolicy::SETTING_CUSTOM_CSS, $css['css'], $tid);
    Database::setSetting(\Slate\Module\StudioBuilder\Http\StudioCodePolicy::SETTING_HEAD_SNIPPET, $head, $tid);
}
say('  + theme     ' . count($tokens) . ' tokens, Cormorant Garamond + Jost, site stylesheet (' . $css['bytes'] . ' bytes)' . ($APPLY ? '' : ' (on --apply)'));

// ── 2. The contact form (Forms plugin) ───────────────────────────────────────────────────────────────────────────
$formSlug = 'solaya-contact';
if ($APPLY) {
    FormsAPI::ensureSchema();
}
$form = Database::row('SELECT id, slug FROM forms_definitions WHERE tenant_id = ? AND slug = ?', [$tid, $formSlug]);
if ($form === null && $APPLY) {
    $fields = FormsAPI::normalizeFields([
        ['type' => 'text', 'label' => 'Your name', 'name' => 'name', 'required' => true],
        ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true],
        ['type' => 'tel', 'label' => 'Phone (optional)', 'name' => 'phone', 'required' => false],
        ['type' => 'textarea', 'label' => 'How can Solaya help?', 'name' => 'message', 'required' => true],
    ]);
    $formId = (int) Database::insert('forms_definitions', [
        'tenant_id' => $tid, 'slug' => $formSlug, 'title' => 'Contact Solaya',
        'description' => 'Ask a question or tell us what you would like help with. We reply by email.',
        'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'submit_label' => 'Send message',
        'success_message' => 'Thank you. Your message has been sent, and we will reply by email.',
        'status' => 'published', 'settings_json' => json_encode(new stdClass()),
    ]);
    $form = ['id' => $formId, 'slug' => $formSlug];
    say("  + form      {$formSlug} (#{$formId})");
} else {
    say('  = form      ' . $formSlug . ($form ? " (#{$form['id']})" : ' (created on --apply)'));
}
$formId = (int) ($form['id'] ?? 0);
$todo[] = 'Set the notification email of the "Contact Solaya" form in Forms, so messages reach the practice.';

$discovery = (int) Database::value("SELECT id FROM booking_services WHERE tenant_id = ? AND slug = 'discovery-call'", [$tid]);
if ($discovery <= 0) {
    fwrite(STDERR, "The Discovery Call service is missing: run php bin/seed-solaya.php first.\n");
    exit(1);
}

// ── 3. Remove the current Studio site ────────────────────────────────────────────────────────────────────────────
$stamp = date('Ymd');
$order = "FIELD(page_type, 'page', 'landing', 'system', 'header_partial', 'footer_partial', 'section_preset'), id";
$current = Database::rows("SELECT id, slug, page_type, title FROM studiobuilder_pages WHERE tenant_id = ? AND status <> 'archived' ORDER BY {$order}", [$tid]);
foreach ($current as $p) {
    say("  - page      #{$p['id']} {$p['page_type']} /{$p['slug']}");
    if ($APPLY) {
        $slug = substr("archived-{$stamp}-{$p['id']}-{$p['slug']}", 0, 180);
        $slug = rtrim($slug, '-');
        $runtime->app->updatePageAddress($actor, (int) $p['id'], ['slug' => $slug, 'route_mode' => 'standalone']);
        $runtime->app->archivePage($actor, (int) $p['id']);
    }
}
$templates = Database::rows('SELECT template_key, template_type FROM studiobuilder_templates WHERE tenant_id = ? ORDER BY id', [$tid]);
foreach ($templates as $t) {
    // The built-in Library sections are Studio's own (reserved, kept in sync by Studio), not site content.
    if (str_starts_with((string) $t['template_key'], \Slate\Module\StudioBuilder\Presets\SectionPresetCatalog::KEY_PREFIX)) {
        continue;
    }
    say("  - template  {$t['template_key']} ({$t['template_type']})");
    if ($APPLY) {
        $runtime->app->deleteTemplate($actor, (string) $t['template_key']);
    }
}

// ── 4. Shared parts ──────────────────────────────────────────────────────────────────────────────────────────────
$menu = [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Services', 'url' => '/services'],
    ['label' => 'Programme', 'url' => '/programme'],
    ['label' => 'About', 'url' => '/about'],
    ['label' => 'Journal', 'url' => '/journal'],
    ['label' => 'Contact', 'url' => '/contact'],
];

// Menu · wordmark · Book, the same on every screen. "Menu" opens a full-width overlay (styled in solaya.css).
$header = [SEC('Header', [
    B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'align' => 'center', 'gap' => 'md', 'wrap' => 'nowrap'], [
        B('layout.offcanvas', ['drawer_id' => 'solaya-menu', 'position' => 'top', 'title' => 'Solaya', 'trigger_text' => 'Menu', 'trigger_variant' => 'ghost'], [
            B('core.nav_menu', ['items' => $menu, 'layout' => 'vertical', 'aria_label' => 'Main menu'], [], ['classNames' => ['sl-overlay-nav']]),
            B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                EYEBROW('Begin here'),
                B('core.text', ['content' => 'Every path starts with a free 20-minute discovery call, online, with nothing to prepare.']),
                B('core.button', ['link' => link_to('Book a free discovery call', '/book-a-call'), 'variant' => 'primary']),
                B('core.link', ['link' => link_to('Or send a message', '/contact'), 'style' => 'underline']),
            ], ['classNames' => ['sl-overlay-aside']]),
        ], ['classNames' => ['sl-hdr-menu']]),
        B('core.link', ['link' => link_to('Solaya', '/'), 'style' => 'plain'], [], ['classNames' => ['sl-hdr-brand']]),
        B('core.button', ['link' => link_to('Book a free call', '/book-a-call'), 'variant' => 'primary', 'size' => 'sm'], [], ['classNames' => ['sl-hdr-book']]),
    ], ['classNames' => ['sl-header']]),
], 'wide', 'surface.primary', ['base' => 'sm'])];

$year = date('Y');
$footer = [SEC('Footer', [
    B('layout.grid', ['columns' => 3, 'gap' => 'lg', 'align' => 'start'], [
        B('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
            B('core.heading', ['text' => 'Solaya', 'level' => 'h3']),
            B('core.text', ['content' => 'Nutrition and hypnosis with Stéphanie Giambertone: support for body and mind, in person and online.', 'size' => 'sm']),
        ], ['classNames' => ['sl-wordmark']]),
        B('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
            B('core.heading', ['text' => 'Explore', 'level' => 'h3']),
            B('core.nav_menu', ['items' => $menu, 'layout' => 'vertical', 'aria_label' => 'Footer menu']),
        ]),
        B('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
            B('core.heading', ['text' => 'Get in touch', 'level' => 'h3']),
            B('core.list', ['style' => 'check', 'items' => [
                ['text' => 'Free 20-minute discovery call'],
                ['text' => 'Sessions in person and online'],
                ['text' => 'Questions answered by email'],
            ]]),
            B('core.link', ['link' => link_to('Send a message', '/contact'), 'style' => 'underline']),
        ]),
    ]),
    B('core.spacer', ['size' => 'lg']),
    B('core.divider', ['style' => 'solid', 'weight' => 'thin', 'width' => 'full']),
    B('core.text', ['content' => "© {$year} Solaya", 'size' => 'sm', 'align' => 'center'], [], ['classNames' => ['sl-legal']]),
], 'wide', 'surface.inverse')];

// ── 5. Pages ─────────────────────────────────────────────────────────────────────────────────────────────────────
$serviceCards = [
    ['heading' => 'Discovery Call', 'body' => 'A free 20-minute call to get to know each other and find the right way to work together.', 'url' => '/book-a-call'],
    ['heading' => 'Nutrition Assessment', 'body' => 'A full one-hour assessment. A 7-day food diary is sent to you beforehand.', 'url' => '/services'],
    ['heading' => 'Emotional Serenity Hypnosis', 'body' => 'A two-hour hypnosis session focused on emotional calm.', 'url' => '/services'],
    ['heading' => 'Spiritual Regressive Hypnosis', 'body' => 'A long session of 3h30 to 4h, booked at least three weeks ahead after a discovery call.', 'url' => '/services'],
    ['heading' => 'Body & Soul Programme', 'body' => 'A three-month programme with daily tracking, goals, 1:1 chat, meal structure and recipes.', 'url' => '/programme'],
];

$pages = [];

$pages['home'] = ['title' => 'Solaya: nutrition and hypnosis for body and mind', 'route' => 'homepage',
    'description' => 'Solaya is the practice of Stéphanie Giambertone: nutrition assessments, hypnosis sessions and the three-month Body & Soul programme. Start with a free discovery call.',
    'sections' => [
        SEC('Hero', [B('core.hero', [
            'eyebrow' => 'Nutrition · Hypnosis · Holistic support',
            'heading' => 'Feel at home in your body and your mind',
            'highlight' => 'at home', 'highlight_italic' => true,
            'subheading' => 'Solaya brings nutrition and hypnosis together, with one practitioner who follows you from the first conversation onwards.',
            'primary_cta' => link_to('Book a free discovery call', '/book-a-call'),
            'media' => $img('hero'),
        ])], 'wide', 'surface.secondary', ['base' => 'lg', 'md' => 'xl']),
        SEC('Approach', [
            EYEBROW('The Solaya approach', true),
            B('core.heading', ['text' => 'What we eat, how we rest and the stories we tell ourselves all shape how we feel.', 'level' => 'h2', 'highlight' => 'how we feel', 'highlight_italic' => true], [], ['classNames' => ['sl-statement'], 'style' => ['align' => ['base' => 'center']]]),
        ], 'normal'),
        SEC('Ways to work together', [
            GRID2('end', [
                B('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
                    EYEBROW('Ways to work together'),
                    B('core.heading', ['text' => 'Five ways to begin', 'level' => 'h2']),
                ]),
                B('core.text', ['content' => 'Every path starts the same way: a free conversation to understand what you need.', 'size' => 'lg']),
            ]),
            B('core.feature_list', ['columns' => 3, 'items' => $serviceCards]),
        ]),
        SEC('How it works', [GRID2('center', [
            B('core.image', ['media' => $img('about'), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['classNames' => ['sl-arch']]),
            B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                EYEBROW('How it works'),
                B('core.heading', ['text' => 'A first conversation, then a path that fits you', 'level' => 'h2', 'highlight' => 'fits you', 'highlight_italic' => true]),
                B('core.rich_text', ['content' => '<p>You start with a <strong>free 20-minute discovery call</strong>. Together you decide what would help most: a nutrition assessment, a hypnosis session, or the three-month Body &amp; Soul programme.</p><p>Some sessions have preparation: a 7-day food diary before a nutrition assessment, and a discovery call before spiritual regressive hypnosis.</p>']),
                B('core.button', ['link' => link_to('See all services', '/services'), 'variant' => 'secondary']),
            ]),
        ])], 'wide', 'surface.secondary'),
        SEC('Solaya in numbers', [B('core.stats', ['items' => [
            ['value' => 20, 'decimals' => 0, 'prefix' => '', 'suffix' => ' min', 'label' => 'Free discovery call'],
            ['value' => 5, 'decimals' => 0, 'prefix' => '', 'suffix' => '', 'label' => 'Ways to work together'],
            ['value' => 3, 'decimals' => 0, 'prefix' => '', 'suffix' => ' months', 'label' => 'Body & Soul programme'],
            ['value' => 21, 'decimals' => 0, 'prefix' => '', 'suffix' => ' days', 'label' => 'Notice for regressive hypnosis'],
        ]])]),
        SEC('Three steps', [
            EYEBROW('Three steps', true),
            B('core.heading', ['text' => 'Simple from the start', 'level' => 'h2'], [], centered()),
            B('core.spacer', ['size' => 'sm']),
            B('layout.grid', ['columns' => 3, 'gap' => 'lg', 'align' => 'start'], [
                STEP('01', 'Talk', 'A free 20-minute discovery call, online, with nothing to prepare.'),
                STEP('02', 'Choose', 'A nutrition assessment, a hypnosis session or the three-month Body & Soul programme.'),
                STEP('03', 'Follow through', 'Follow-up sessions and, in the programme, daily tracking and a 1:1 chat.'),
            ]),
        ]),
        SEC('Start here', [B('layout.section', ['tag' => 'section', 'content_width' => 'boxed', 'padding_y' => 'none', 'min_height' => 'auto'], [
            B('layout.container', ['width' => 'constrained', 'alignment' => 'center', 'padding' => 'none'], [
                B('core.card', ['variant' => 'filled', 'padding' => 'lg'], [
                    EYEBROW('Begin here', true),
                    B('core.heading', ['text' => 'Not sure where to start?', 'level' => 'h2'], [], centered()),
                    B('core.text', ['content' => 'Book the free discovery call. It takes 20 minutes and there is nothing to prepare.', 'align' => 'center']),
                    B('core.button', ['link' => link_to('Book a free discovery call', '/book-a-call'), 'variant' => 'primary', 'size' => 'lg']),
                ], ['classNames' => ['sl-cta'], 'style' => ['align' => ['base' => 'center']]]),
            ]),
        ])]),
    ]];

$pages['services'] = ['title' => 'Services', 'route' => 'standalone',
    'description' => 'Discovery call, nutrition assessment, emotional serenity hypnosis, spiritual regressive hypnosis and Body & Soul follow-up: durations, payment and booking rules.',
    'sections' => [
        SEC('Intro', [
            EYEBROW('What we offer'),
            B('core.heading', ['text' => 'Services', 'level' => 'h1']),
            B('core.text', ['content' => 'Five ways to work with Solaya. Each one starts with a conversation, and the free discovery call is open to everyone.', 'size' => 'lg']),
            B('layout.flex', ['direction' => 'row', 'gap' => 'lg', 'wrap' => 'wrap', 'align' => 'center'], [
                B('core.icon', ['name' => 'clock', 'label' => 'Durations from 20 minutes to 4 hours', 'size' => 'md']),
                B('core.icon', ['name' => 'calendar', 'label' => 'Book online', 'size' => 'md']),
                B('core.icon', ['name' => 'leaf', 'label' => 'Nutrition and hypnosis', 'size' => 'md']),
            ]),
        ], 'wide', 'surface.secondary'),
        SEC('Book', [B('booking.services', ['heading' => 'Book a session', 'cta_label' => 'Book', 'show_prices' => false], [], ['bindings' => ['items' => ['provider' => 'booking.services', 'params' => []]]])]),
        SEC('Each service', [
            B('core.heading', ['text' => 'Each service in detail', 'level' => 'h2']),
            B('core.tabs', ['options' => ['position' => 'top'], 'items' => [
                ['label' => 'Discovery Call', 'content' => 'A free 20-minute introductory call to get acquainted and point you in the right direction. Online.'],
                ['label' => 'Nutrition', 'content' => 'A full one-hour nutrition assessment. A 7-day food diary is sent to you before the session. This one-time assessment does not include access to the programme app.'],
                ['label' => 'Serenity hypnosis', 'content' => 'A two-hour hypnosis session focused on emotional calm.'],
                ['label' => 'Regressive hypnosis', 'content' => 'A long session of 3h30 to 4h. A prior discovery call is required, and the session must be booked at least three weeks ahead. The first hour is paid when you book, the balance on the day.'],
                ['label' => 'Body & Soul follow-up', 'content' => 'A 30 or 45-minute follow-up for clients of the Body & Soul programme, included in the monthly programme payment.'],
            ]]),
        ]),
        SEC('At a glance', [
            B('core.heading', ['text' => 'At a glance', 'level' => 'h2']),
            B('core.table', ['caption' => 'Durations and how each session is paid', 'striped' => true, 'header' => 'Service | Duration | Payment', 'rows' => [
                ['cells' => 'Discovery Call | 20 min | Free'],
                ['cells' => 'Nutrition Assessment | 1 hour | Paid in full when booking'],
                ['cells' => 'Emotional Serenity Hypnosis | 2 hours | Paid in full when booking'],
                ['cells' => 'Spiritual Regressive Hypnosis | 3h30 to 4h | First hour when booking, balance on the day'],
                ['cells' => 'Body & Soul Follow-up | 30 or 45 min | Included in the monthly programme payment'],
            ]]),
            B('payment.button', ['label' => 'Pay for a nutrition assessment', 'amount' => 1, 'currency' => 'EUR', 'description' => 'Nutrition Assessment', 'success_path' => 'book-a-call'], [], staff_only()),
        ], 'wide', 'surface.secondary'),
        SEC('Questions', [
            B('core.heading', ['text' => 'Questions', 'level' => 'h2']),
            B('core.accordion', ['allow_multiple' => false, 'items' => [
                ['heading' => 'Why is a discovery call needed before regressive hypnosis?', 'body' => 'It is a long and deep session. The discovery call makes sure it is the right step for you and that you know what to expect.'],
                ['heading' => 'How far ahead should I book regressive hypnosis?', 'body' => 'At least three weeks ahead. If you choose it without a discovery call, booking takes you to the discovery call first.'],
                ['heading' => 'What do I prepare for a nutrition assessment?', 'body' => 'A 7-day food diary, which is sent to you before the session.'],
                ['heading' => 'Is the programme app included in a nutrition assessment?', 'body' => 'No. The app comes with the three-month Body & Soul programme.'],
            ]]),
        ]),
    ]];
$todo[] = 'Services: set real prices in Booking → Services, then set the "Pay for a nutrition assessment" payment button amount and make it visible to everyone (Advanced → Visibility), or remove it.';

$pages['programme'] = ['title' => 'Body & Soul Programme', 'route' => 'standalone',
    'description' => 'The three-month Body & Soul programme: daily tracking, goals, 1:1 chat, meal structure, recipes and an end-of-programme summary, with app access.',
    'sections' => [
        SEC('Intro', [
            GRID2('center', [
                B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                    EYEBROW('Three months'),
                    B('core.heading', ['text' => 'Body & Soul Programme', 'level' => 'h1', 'highlight' => 'Body & Soul', 'highlight_italic' => true]),
                    B('core.text', ['content' => 'Three months of support for lasting change, with a downloadable app and regular follow-up sessions.', 'size' => 'lg']),
                    B('core.modal', ['modal_id' => 'programme-app', 'title' => 'What the app includes', 'trigger_text' => 'What does the app include?', 'trigger_variant' => 'outline', 'size' => 'md'], [
                        B('core.rich_text', ['content' => '<p>The programme app gives you daily tracking, your goals, a 1:1 chat, your meal structure and recipes, and a summary at the end of the programme.</p>']),
                    ]),
                ]),
                B('core.image', ['media' => $img('programme'), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['classNames' => ['sl-arch']]),
            ]),
        ], 'wide', 'surface.secondary'),
        SEC('What is included', [
            B('core.heading', ['text' => 'What is included', 'level' => 'h2']),
            B('core.list', ['style' => 'check', 'items' => [
                ['text' => 'Daily tracking'], ['text' => 'Personal goals'], ['text' => '1:1 chat'],
                ['text' => 'Meal structure and recipes'], ['text' => 'Follow-up sessions of 30 or 45 minutes'],
                ['text' => 'An end-of-programme summary'],
            ]]),
            B('core.countdown', ['label' => 'Next programme intake in', 'target' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+60 days')), 'done_text' => 'The programme intake is open.'], [], staff_only()),
        ]),
        SEC('Join', [B('membership.plans', ['heading' => 'Join the programme', 'cta_label' => 'Join', 'show_prices' => false], [], ['bindings' => ['items' => ['provider' => 'membership.plans', 'params' => []]]])], 'normal', 'surface.secondary'),
        SEC('Questions', [
            B('core.heading', ['text' => 'Questions about the programme?', 'level' => 'h2']),
            B('forms.form_card', ['button_label' => 'Ask a question', 'show_description' => true], [], ['bindings' => ['form' => ['provider' => 'forms.form', 'params' => ['slug' => $formSlug]]]]),
        ], 'normal'),
    ]];
$todo[] = 'Programme: set the programme price in Membership → Plans. The "Next programme intake" countdown has a placeholder date: set the real date and make it visible to everyone, or remove it.';

$pages['about'] = ['title' => 'About', 'route' => 'standalone',
    'description' => 'Solaya is the practice of Stéphanie Giambertone, bringing together nutrition and hypnosis.',
    'sections' => [
        SEC('About', [GRID2('center', [
            B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                EYEBROW('About'),
                B('core.heading', ['text' => 'A practice for body and mind', 'level' => 'h1', 'highlight' => 'body and mind', 'highlight_italic' => true]),
                B('core.rich_text', ['content' => '<p>Solaya is the practice of <strong>Stéphanie Giambertone</strong>. It brings nutrition and hypnosis together, so that body and mind are looked after as one.</p><p>Every client starts with a free discovery call. From there, Stéphanie suggests a nutrition assessment, a hypnosis session or the three-month Body &amp; Soul programme, and follows each person through it.</p>']),
            ]),
            B('core.image', ['media' => $img('about'), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['classNames' => ['sl-arch']]),
        ])], 'wide', 'surface.secondary', ['base' => 'lg', 'md' => 'xl']),
        SEC('Approach', [B('core.quote', ['text' => 'Nutrition and hypnosis, brought together so that body and mind are looked after as one.', 'author' => 'Solaya', 'role' => 'Our approach', 'align' => 'center'])], 'normal'),
        SEC('Principles', [
            EYEBROW('Principles', true),
            B('core.carousel', ['options' => ['autoplay_ms' => 0, 'loop' => true, 'per_view' => 1], 'slides' => [
                ['image' => null, 'quote' => 'A conversation comes before any plan.', 'author' => 'Listen first', 'caption' => ''],
                ['image' => null, 'quote' => 'Body and mind are looked after as one.', 'author' => 'The whole person', 'caption' => ''],
                ['image' => null, 'quote' => 'Support continues between sessions, not only during them.', 'author' => 'Follow through', 'caption' => ''],
            ]]),
        ], 'normal', 'surface.secondary'),
        SEC('Video', [B('core.video', ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'aspect_ratio' => '16:9', 'controls' => true], [], staff_only())], 'normal', 'surface.primary', ['base' => 'md', 'md' => 'lg'], 'authenticated'),
        SEC('Gallery', [
            EYEBROW('The feel of Solaya'),
            B('core.heading', ['text' => 'Quiet, warm, unhurried', 'level' => 'h2']),
            B('core.gallery', ['columns' => '3', 'gap' => 'md', 'aspect_ratio' => '4:3', 'rounded' => true, 'images' => [
                ['url' => $imgUrl('nutrition'), 'alt' => $alt('nutrition'), 'caption' => 'Nutrition'],
                ['url' => $imgUrl('hypnosis'), 'alt' => $alt('hypnosis'), 'caption' => 'Hypnosis'],
                ['url' => $imgUrl('programme'), 'alt' => $alt('programme'), 'caption' => 'Body & Soul'],
            ]]),
        ]),
    ]];
$todo[] = 'About: the video block shows a placeholder video to staff only. Replace it with a real Solaya video and make it public, or remove it. Add Stéphanie\'s own biography and a real photo when available.';

$pages['journal'] = ['title' => 'Journal', 'route' => 'standalone',
    'description' => 'Notes from Solaya on preparing for sessions and getting the most from them.',
    'sections' => [
        SEC('Journal', [
            EYEBROW('Notes from Solaya'),
            B('core.heading', ['text' => 'Journal', 'level' => 'h1']),
            B('core.text', ['content' => 'Practical notes on preparing for your sessions.', 'size' => 'lg']),
            B('core.query_loop', ['source' => 'posts', 'category' => 'journal', 'columns' => 3, 'per_page' => 6, 'show_author' => false, 'read_more_text' => 'Read', 'empty_message' => 'No articles yet.']),
        ]),
    ]];

$pages['contact'] = ['title' => 'Contact', 'route' => 'standalone',
    'description' => 'Send Solaya a message, or book a free 20-minute discovery call.',
    'sections' => [
        SEC('Contact', [
            EYEBROW('Get in touch'),
            B('core.heading', ['text' => 'Contact', 'level' => 'h1']),
            GRID2('start', [
                B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                    B('core.heading', ['text' => 'Send a message', 'level' => 'h2']),
                    B('forms.embed', [], [], ['bindings' => ['form' => ['provider' => 'forms.form_embed', 'params' => ['id' => $formId]]]]),
                ]),
                B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                    B('core.heading', ['text' => 'Prefer to talk?', 'level' => 'h2']),
                    B('core.text', ['content' => 'The discovery call is free and takes 20 minutes. Ask for a call back and we will get in touch to find a time.']),
                    B('core.modal', ['modal_id' => 'call-back', 'title' => 'Request a call back', 'trigger_text' => 'Request a call back', 'trigger_variant' => 'primary', 'size' => 'md'], [
                        B('core.form', ['form_name' => 'call_back', 'submit_text' => 'Request a call back', 'submit_variant' => 'primary', 'method' => 'post', 'action' => ''], [
                            B('core.form_field', ['field_type' => 'text', 'label' => 'Your name', 'name' => 'name', 'required' => true]),
                            B('core.form_field', ['field_type' => 'tel', 'label' => 'Phone', 'name' => 'phone', 'required' => true]),
                            B('core.form_field', ['field_type' => 'select', 'label' => 'Best time to call', 'name' => 'best_time', 'options' => 'Morning, Afternoon, Evening']),
                        ]),
                    ]),
                    B('core.link', ['link' => link_to('Or book the discovery call online', '/book-a-call'), 'style' => 'underline']),
                ]),
            ]),
        ]),
        SEC('Map', [B('core.map', ['title' => 'Solaya', 'height' => 'md', 'zoom' => 13], [], staff_only())], 'wide', 'surface.secondary', ['base' => 'md', 'md' => 'lg'], 'authenticated'),
    ]];
$todo[] = 'Contact: the map shows a placeholder location to staff only. Set the practice address coordinates and make it public, or remove it. The call-back form in the modal is a native Studio form: check where its submissions go before relying on it.';

$pages['book-a-call'] = ['title' => 'Book a discovery call', 'route' => 'standalone',
    'description' => 'Book a free 20-minute discovery call with Solaya.',
    'sections' => [
        SEC('Book', [
            EYEBROW('Free · 20 minutes · Online'),
            B('core.heading', ['text' => 'Book a free discovery call', 'level' => 'h1']),
            B('core.text', ['content' => 'Twenty minutes, free, online. Choose a time that suits you.']),
            B('booking.embed', ['min_height' => 640], [], ['bindings' => ['service' => ['provider' => 'booking.embed_target', 'params' => ['id' => $discovery]]]]),
            B('payment.link', ['label' => 'Pay online', 'url' => 'https://buy.stripe.com/test_placeholder'], [], staff_only()),
        ], 'normal'),
    ]];
$todo[] = 'Book: the payment link is a placeholder shown to staff only. Paste a real Stripe payment link and make it public, or remove it. Set Stéphanie\'s weekly hours in Booking → Providers so the calendar has times.';

// Journal articles: listed by the Journal page's Query Loop and laid out by the single template.
$articles = [
    'what-to-expect-from-a-discovery-call' => ['What to expect from a discovery call', 'journal-1',
        'The discovery call is free, takes 20 minutes and happens online.',
        '<p>The discovery call is a free, 20-minute conversation online. It is the first step for every client.</p><p>You talk about what brings you to Solaya and what you would like to change. At the end you know which next step makes sense: a nutrition assessment, a hypnosis session or the Body &amp; Soul programme.</p><p>There is nothing to prepare.</p>'],
    'preparing-for-your-nutrition-assessment' => ['Preparing for your nutrition assessment', 'journal-2',
        'Before the one-hour assessment, you keep a 7-day food diary.',
        '<p>The nutrition assessment lasts one hour. Before it, you receive a 7-day food diary to fill in.</p><p>The diary gives the session a real starting point: what you eat, when, and how you feel.</p><p>The assessment is a one-time session and does not include the programme app. The app comes with the three-month Body &amp; Soul programme.</p>'],
    'booking-spiritual-regressive-hypnosis' => ['Booking spiritual regressive hypnosis', 'journal-3',
        'A long session, booked at least three weeks ahead, after a discovery call.',
        '<p>Spiritual regressive hypnosis is a long session of three and a half to four hours.</p><p>Two things come first: a discovery call, and booking at least three weeks ahead. If you try to book it without a discovery call, booking takes you to the discovery call instead.</p><p>The first hour is paid when you book, and the balance on the day.</p>'],
];
foreach ($articles as $slug => [$title, $image, $excerpt, $body]) {
    $pages[$slug] = ['title' => $title, 'route' => 'standalone', 'description' => $excerpt,
        'settings' => ['category' => 'journal', 'excerpt' => $excerpt],
        'sections' => [
            SEC('Article', [
                B('core.image', ['media' => $img($image), 'aspect_ratio' => '16:9', 'rounded' => true]),
                B('core.rich_text', ['content' => $body]),
                B('core.button', ['link' => link_to('Book a free discovery call', '/book-a-call'), 'variant' => 'secondary']),
            ], 'narrow'),
        ]];
}

// ── 6. Theme templates: single article, archive and search, 404 ─────────────────────────────────────────────────
$themeTemplates = [
    'solaya-journal-article' => ['Journal article', 'single', [['type' => 'include', 'condition' => 'category', 'value' => 'journal']], [
        SEC('Article', [
            B('theme.post_title', ['level' => 'h1']),
            B('theme.post_meta', ['show_author' => false, 'show_category' => true, 'show_date' => true]),
            B('theme.field_value', ['field' => 'summary', 'fallback' => '', 'show_label' => false, 'tag' => 'div']),
            B('theme.post_content'),
        ], 'narrow'),
    ]],
    'solaya-archive' => ['Archive and search', 'archive', [['type' => 'include', 'condition' => 'archive'], ['type' => 'include', 'condition' => 'search']], [
        SEC('Archive', [
            B('theme.archive_title', ['level' => 'h1']),
            B('theme.search_box', ['placeholder' => 'Search the journal', 'button_text' => 'Search']),
            B('core.query_loop', ['source' => 'posts', 'columns' => 3, 'per_page' => 9, 'show_author' => false]),
        ]),
    ]],
    'solaya-not-found' => ['Page not found', 'not_found', [['type' => 'include', 'condition' => '404']], [
        SEC('Not found', [
            B('core.heading', ['text' => 'This page could not be found', 'level' => 'h1']),
            B('core.text', ['content' => 'The address may have changed. Try a search, or go back to the home page.']),
            B('theme.search_box', ['placeholder' => 'Search Solaya', 'button_text' => 'Search']),
            B('core.button', ['link' => link_to('Back to the home page', '/'), 'variant' => 'primary']),
        ], 'normal'),
    ]],
];

/** Dry run: validate a document as a save would, and name every problem instead of stopping at the first. */
function check_document(array $doc): string
{
    global $runtime;
    try {
        \Slate\Module\StudioBuilder\Document\ValidatedDocument::from($doc, $runtime->registry, ['allow_locked' => true]);
        return 'valid';
    } catch (\Slate\Module\StudioBuilder\Exception\StudioValidationException $e) {
        $GLOBALS['invalid'] = true;
        return "INVALID:\n" . implode("\n", array_map(fn($i) => '      ' . ($i['path'] ?? '') . ' ' . ($i['code'] ?? '') . ': ' . ($i['message'] ?? ''), $e->errors()));
    }
}

// ── 7. Build ─────────────────────────────────────────────────────────────────────────────────────────────────────
/** Create a page of `$pageType`, save `$sections` as its draft and publish it. */
function build_page(string $slug, string $title, string $pageType, string $route, array $sections, array $settings = [], string $description = '', ?string $docType = null, ?string $seoTitle = null): int
{
    global $runtime, $actor, $APPLY;
    $doc = CanonicalDocumentSchema::emptyDocument($docType ?? $pageType, 'default', $seoTitle ?? $title);
    $doc['sections'] = $sections;
    $doc['settings'] = array_replace($doc['settings'], $settings);
    if ($description !== '') {
        $doc['seo']['description'] = $description;
    }
    if ($pageType === 'system') {
        $doc['seo']['robots'] = 'noindex,nofollow'; // a template's own page is never public
    }
    if (!$APPLY) {
        say("  + {$pageType}" . str_repeat(' ', max(1, 15 - strlen($pageType))) . "/{$slug}  (" . count($sections) . ' sections, ' . check_document($doc) . ')');
        return 0;
    }
    $created = $runtime->app->createPage($actor, $title, $slug, $pageType, $route);
    $pageId = (int) $created['page']['id'];
    $draft = $runtime->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id'], 'manual', 'Solaya site');
    $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'Solaya site');
    say("  + {$pageType}" . str_repeat(' ', max(1, 15 - strlen($pageType))) . "/{$slug}  (#{$pageId})");
    return $pageId;
}

build_page('default', 'Header', 'header_partial', 'standalone', $header);
build_page('default', 'Footer', 'footer_partial', 'standalone', $footer);
foreach ($pages as $slug => $p) {
    $seo = $p['route'] === 'homepage' ? $p['title'] : "{$p['title']} | Solaya";
    build_page($slug, $p['title'], 'page', $p['route'], $p['sections'], $p['settings'] ?? [], $p['description'] ?? '', null, $seo);
}
foreach ($themeTemplates as $key => [$name, $role, $rules, $sections]) {
    $doc = CanonicalDocumentSchema::emptyDocument('system', 'default', $name);
    $doc['seo']['robots'] = 'noindex,nofollow';
    $doc['settings']['conditions'] = ['rules' => $rules];
    $doc['settings']['template_type'] = $role === 'not_found' ? '404' : $role;
    $doc['sections'] = $sections;
    if ($APPLY) {
        $runtime->app->saveTemplate($actor, $key, 'page_template', $role, $name, "Solaya {$name} template", $doc);
        build_page('template-' . $key, "[Theme] {$name}", 'system', 'standalone', $sections, ['conditions' => ['rules' => $rules], 'template_type' => $doc['settings']['template_type']], '', null, "{$name} | Solaya");
    } else {
        say("  + template  {$key} ({$role}, " . check_document($doc) . ')');
    }
}

if ($APPLY) {
    Database::setSetting('site_name', 'Solaya');
}

// ── 8. Coverage: every native block is used somewhere ────────────────────────────────────────────────────────────
$used = [];
$walk = static function (array $blocks) use (&$walk, &$used): void {
    foreach ($blocks as $b) { $used[$b['type']] = true; $walk($b['children'] ?? []); }
};
foreach (array_merge([$header, $footer], array_column($pages, 'sections'), array_map(fn($t) => $t[3], $themeTemplates)) as $secs) {
    foreach ($secs as $s) { $walk($s['blocks']); }
}
$native = array_filter(array_keys($defs), fn($t) => preg_match('/^(core|layout|theme|booking|forms|membership|payment)\./', $t) === 1);
$missing = array_values(array_diff($native, array_keys($used)));
say("\nNative blocks used: " . count(array_intersect($native, array_keys($used))) . ' / ' . count($native) . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

if (!empty($GLOBALS['invalid'])) {
    say("\nSome documents are invalid (above): fix them before --apply.");
    exit(1);
}

say("\nStill needs a human:");
foreach ($todo as $i => $t) {
    say('  ' . ($i + 1) . '. ' . $t);
}
