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
    if (isset($opt['responsive'])) {
        $block['responsive'] = $opt['responsive'];
    }
    return $block;
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
    return [
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'global_ref' => null,
        'layout'     => ['background_token' => $surface, 'columns' => ['base' => 1, 'md' => 1], 'gap' => 'md', 'padding_y' => $padding, 'width' => $width],
        'visibility' => ['auth_state' => $audience, 'devices' => ['base', 'sm', 'md', 'lg']],
        'blocks'     => $blocks,
    ];
}

function link_to(string $label, string $href): array { return ['label' => $label, 'href' => $href, 'target' => '_self']; }
function centered(): array { return ['style' => ['align' => ['base' => 'center']]]; }

/** Shown only to signed-in staff until a human supplies the missing fact (see the list at the end). */
function staff_only(): array { return ['visibility' => ['auth_state' => 'authenticated', 'devices' => ['base', 'sm', 'md', 'lg']]]; }

const PHONE = ['base', 'sm'];
const WIDE  = ['md', 'lg'];

// ── 1. Images: soft generated artwork (no stock photos), registered in the media library ────────────────────────
// Under /uploads/media/: the image renderer only serves files from the media library's managed folders.
$artDir = SLATE_ROOT . '/uploads/media/solaya-site';
$art = [
    'hero'      => [[244, 226, 205], [222, 160, 120], 'Warm sunrise colours over soft sand tones'],
    'about'     => [[232, 236, 224], [150, 170, 140], 'Calm sage-green abstract light'],
    'nutrition' => [[246, 236, 214], [196, 168, 92], 'Golden abstract tones suggesting nourishment'],
    'hypnosis'  => [[226, 228, 240], [130, 128, 176], 'Soft lavender abstract light, quiet and restful'],
    'programme' => [[240, 230, 222], [176, 120, 104], 'Terracotta abstract light'],
    'journal-1' => [[236, 232, 220], [170, 150, 120], 'Neutral abstract light'],
    'journal-2' => [[230, 236, 230], [120, 150, 130], 'Green abstract light'],
    'journal-3' => [[238, 230, 236], [160, 120, 150], 'Rose abstract light'],
];
$media = [];
if ($APPLY) {
    if (!is_dir($artDir) && !mkdir($artDir, 0755, true)) {
        throw new RuntimeException("Cannot create {$artDir}");
    }
    foreach ($art as $name => [$from, $to]) {
        $file = "{$artDir}/{$name}.jpg";
        if (!is_file($file)) {
            solaya_paint($file, $from, $to);
        }
    }
    Media::ensureSchema();
    Media::importFolder('media/solaya-site');
}
foreach ($art as $name => [, , $alt]) {
    $path = "/uploads/media/solaya-site/{$name}.jpg";
    $id = $APPLY ? (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, $path]) : 1;
    if ($APPLY && $id <= 0) {
        throw new RuntimeException("Image {$path} was not registered in the media library.");
    }
    $media[$name] = ['id' => $id, 'path' => $path, 'alt' => $alt];
}
say('  + images    ' . count($art) . ' generated in /uploads/media/solaya-site/');
$img = static fn(string $n): array => ['media_id' => $media[$n]['id'], 'alt' => $media[$n]['alt'], 'focal_point' => [0.5, 0.5]];
$imgUrl = static fn(string $n): string => (defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '') . $media[$n]['path'];

/** A soft diagonal gradient with a pale sun, as a JPEG. */
function solaya_paint(string $file, array $from, array $to): void
{
    $w = 1600; $h = 1000;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / ($h - 1);
        $c = imagecolorallocate($im, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($im, 0, $y, $w, $y, $c);
    }
    imagealphablending($im, true);
    for ($r = 420; $r > 0; $r -= 6) {
        $a = (int) max(0, min(127, 127 - (420 - $r) / 6));
        imagefilledellipse($im, (int) ($w * 0.72), (int) ($h * 0.42), $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 248, 236, $a));
    }
    imagejpeg($im, $file, 86);
    imagedestroy($im);
}

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

$header = [SEC('Header', [
    // Top-aligned: the brand link and the menu keep the base paragraph/list margin below them, so centring would drop the button.
    B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'align' => 'start', 'gap' => 'md', 'wrap' => 'nowrap'], [
        B('core.link', ['link' => link_to('Solaya', '/'), 'style' => 'plain']),
        B('core.nav_menu', ['items' => $menu, 'layout' => 'horizontal', 'aria_label' => 'Main menu', 'align' => 'center'], [], ['visibility' => ['auth_state' => 'any', 'devices' => WIDE]]),
        B('core.button', ['link' => link_to('Book a free call', '/book-a-call'), 'variant' => 'primary', 'size' => 'sm'], [], ['visibility' => ['auth_state' => 'any', 'devices' => WIDE]]),
        B('layout.offcanvas', ['drawer_id' => 'solaya-menu', 'position' => 'right', 'title' => 'Menu', 'trigger_text' => 'Menu', 'trigger_variant' => 'outline'], [
            B('core.nav_menu', ['items' => $menu, 'layout' => 'vertical', 'aria_label' => 'Main menu']),
            B('core.button', ['link' => link_to('Book a free call', '/book-a-call'), 'variant' => 'primary', 'full_width' => true]),
        ], ['visibility' => ['auth_state' => 'any', 'devices' => PHONE]]),
    ]),
], 'wide', 'surface.primary', ['base' => 'sm'])];

$year = date('Y');
$footer = [SEC('Footer', [
    B('layout.grid', ['columns' => 3, 'gap' => 'lg', 'align' => 'start'], [
        B('core.container', ['direction' => 'vertical', 'gap' => 'sm'], [
            B('core.heading', ['text' => 'Solaya', 'level' => 'h3']),
            B('core.text', ['content' => 'Nutrition and hypnosis with Stéphanie Giambertone: support for body and mind, in person and online.', 'size' => 'sm']),
        ]),
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
    B('core.spacer', ['size' => 'md']),
    B('core.divider', ['style' => 'solid', 'weight' => 'thin', 'width' => 'full']),
    B('core.text', ['content' => "© {$year} Solaya. All rights reserved.", 'size' => 'sm', 'align' => 'center']),
], 'wide', 'surface.secondary')];

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
        SEC('Ways to work together', [
            B('core.heading', ['text' => 'Ways to work together', 'level' => 'h2'], [], centered()),
            B('core.text', ['content' => 'Every path starts the same way: a free conversation to understand what you need.', 'align' => 'center']),
            B('core.feature_list', ['columns' => 3, 'items' => $serviceCards]),
        ]),
        SEC('Solaya in numbers', [B('core.stats', ['items' => [
            ['value' => 20, 'decimals' => 0, 'prefix' => '', 'suffix' => ' min', 'label' => 'Free discovery call'],
            ['value' => 5, 'decimals' => 0, 'prefix' => '', 'suffix' => '', 'label' => 'Ways to work together'],
            ['value' => 3, 'decimals' => 0, 'prefix' => '', 'suffix' => ' months', 'label' => 'Body & Soul programme'],
            ['value' => 21, 'decimals' => 0, 'prefix' => '', 'suffix' => ' days', 'label' => 'Notice for a regressive hypnosis session'],
        ]])], 'wide', 'surface.secondary'),
        SEC('How it works', [
            GRID2('center', [
                B('core.image', ['media' => $img('about'), 'aspect_ratio' => '4:3', 'rounded' => true]),
                B('core.container', ['direction' => 'vertical', 'gap' => 'md'], [
                    B('core.heading', ['text' => 'How it works', 'level' => 'h2']),
                    B('core.rich_text', ['content' => '<p>You start with a <strong>free 20-minute discovery call</strong>. Together you decide what would help most: a nutrition assessment, a hypnosis session, or the three-month Body &amp; Soul programme.</p><p>Some sessions have preparation: a 7-day food diary before a nutrition assessment, and a discovery call before spiritual regressive hypnosis.</p>']),
                    B('core.button', ['link' => link_to('See all services', '/services'), 'variant' => 'secondary']),
                ]),
            ]),
        ]),
        SEC('Three steps', [
            B('core.heading', ['text' => 'Three steps', 'level' => 'h2'], [], centered()),
            B('core.carousel', ['options' => ['autoplay_ms' => 0, 'loop' => true, 'per_view' => 1], 'slides' => [
                ['image' => $img('journal-1'), 'quote' => 'Talk: a free 20-minute discovery call.', 'author' => 'Step 1', 'caption' => ''],
                ['image' => $img('nutrition'), 'quote' => 'Choose: a session or the three-month programme.', 'author' => 'Step 2', 'caption' => ''],
                ['image' => $img('programme'), 'quote' => 'Follow through, with support between sessions.', 'author' => 'Step 3', 'caption' => ''],
            ]]),
        ], 'normal'),
        SEC('Approach', [B('core.quote', ['text' => 'What we eat, how we rest and the stories we tell ourselves all shape how we feel.', 'author' => 'Solaya', 'role' => 'Our approach', 'align' => 'center'])], 'normal', 'surface.secondary'),
        SEC('Start here', [B('layout.section', ['tag' => 'section', 'content_width' => 'boxed', 'padding_y' => 'md', 'min_height' => 'auto'], [
            B('layout.container', ['width' => 'compact', 'alignment' => 'center', 'padding' => 'none'], [
                B('core.card', ['variant' => 'filled', 'padding' => 'lg'], [
                    B('core.heading', ['text' => 'Not sure where to start?', 'level' => 'h2']),
                    B('core.text', ['content' => 'Book the free discovery call. It takes 20 minutes and there is nothing to prepare.']),
                    B('core.button', ['link' => link_to('Book a free discovery call', '/book-a-call'), 'variant' => 'primary', 'size' => 'lg']),
                ], centered()),
            ]),
        ])], 'normal'),
    ]];

$pages['services'] = ['title' => 'Services', 'route' => 'standalone',
    'description' => 'Discovery call, nutrition assessment, emotional serenity hypnosis, spiritual regressive hypnosis and Body & Soul follow-up: durations, payment and booking rules.',
    'sections' => [
        SEC('Intro', [
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
                    B('core.heading', ['text' => 'Body & Soul Programme', 'level' => 'h1', 'highlight' => 'Body & Soul']),
                    B('core.text', ['content' => 'Three months of support for lasting change, with a downloadable app and regular follow-up sessions.', 'size' => 'lg']),
                    B('core.modal', ['modal_id' => 'programme-app', 'title' => 'What the app includes', 'trigger_text' => 'What does the app include?', 'trigger_variant' => 'outline', 'size' => 'md'], [
                        B('core.rich_text', ['content' => '<p>The programme app gives you daily tracking, your goals, a 1:1 chat, your meal structure and recipes, and a summary at the end of the programme.</p>']),
                    ]),
                ]),
                B('core.image', ['media' => $img('programme'), 'aspect_ratio' => '4:3', 'rounded' => true]),
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
        SEC('About', [
            B('core.heading', ['text' => 'About Solaya', 'level' => 'h1']),
            GRID2('start', [
                B('core.image', ['media' => $img('about'), 'aspect_ratio' => '4:3', 'rounded' => true, 'caption' => '']),
                B('core.rich_text', ['content' => '<p>Solaya is the practice of <strong>Stéphanie Giambertone</strong>. It brings nutrition and hypnosis together, so that body and mind are looked after as one.</p><p>Every client starts with a free discovery call. From there, Stéphanie suggests a nutrition assessment, a hypnosis session or the three-month Body &amp; Soul programme, and follows each person through it.</p>']),
            ]),
        ]),
        SEC('Video', [B('core.video', ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'aspect_ratio' => '16:9', 'controls' => true], [], staff_only())], 'normal', 'surface.primary', ['base' => 'md', 'md' => 'lg'], 'authenticated'),
        SEC('Gallery', [
            B('core.heading', ['text' => 'The feel of Solaya', 'level' => 'h2']),
            B('core.gallery', ['columns' => '3', 'gap' => 'md', 'aspect_ratio' => '4:3', 'rounded' => true, 'images' => [
                ['url' => $imgUrl('nutrition'), 'alt' => $media['nutrition']['alt'], 'caption' => 'Nutrition'],
                ['url' => $imgUrl('hypnosis'), 'alt' => $media['hypnosis']['alt'], 'caption' => 'Hypnosis'],
                ['url' => $imgUrl('programme'), 'alt' => $media['programme']['alt'], 'caption' => 'Body & Soul'],
            ]]),
        ], 'wide', 'surface.secondary'),
    ]];
$todo[] = 'About: the video block shows a placeholder video to staff only. Replace it with a real Solaya video and make it public, or remove it. Add Stéphanie\'s own biography and a real photo when available.';

$pages['journal'] = ['title' => 'Journal', 'route' => 'standalone',
    'description' => 'Notes from Solaya on preparing for sessions and getting the most from them.',
    'sections' => [
        SEC('Journal', [
            B('core.heading', ['text' => 'Journal', 'level' => 'h1']),
            B('core.text', ['content' => 'Practical notes on preparing for your sessions.', 'size' => 'lg']),
            B('core.query_loop', ['source' => 'posts', 'category' => 'journal', 'columns' => 3, 'per_page' => 6, 'show_author' => false, 'read_more_text' => 'Read', 'empty_message' => 'No articles yet.']),
        ]),
    ]];

$pages['contact'] = ['title' => 'Contact', 'route' => 'standalone',
    'description' => 'Send Solaya a message, or book a free 20-minute discovery call.',
    'sections' => [
        SEC('Contact', [
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
