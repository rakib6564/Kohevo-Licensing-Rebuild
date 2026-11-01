<?php
// Chef Gregory's (chefgregory.com) rebuilt in Kohevo Studio: content types with meta fields and one Single template each.
//
//   cd <site> && php <bundle>/build/site.php      (data/capture.json and media/ come from the capture step; see README)
declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

require __DIR__ . '/lib.php';

require_once __DIR__ . '/capture.php';

// ── Media ──────────────────────────────────────────────────────────────────────
$dst = getcwd() . '/uploads/media/chef';
if (!is_dir($dst)) { mkdir($dst, 0755, true); }
foreach (glob("{$BUNDLE}/media/*") ?: [] as $file) {
    $name = basename($file);
    if (!is_file("{$dst}/{$name}")) { copy($file, "{$dst}/{$name}"); }
    if (MID($name) <= 0) {
        $size = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => mime_content_type("{$dst}/{$name}") ?: ''];
        Media::register(MEDIA_DIR . $name, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $name]);
    }
}
say('  + media');

// ── Theme ──────────────────────────────────────────────────────────────────────
$runtime->app->saveDesignTokens($actor, 'default', [
    'surface.page' => '#ffffff', 'surface.primary' => '#ffffff', 'surface.secondary' => '#f9f7f2', 'surface.muted' => '#f9f7f2', 'surface.accent' => '#ff7f00', 'surface.inverse' => '#0d0d0d',
    'text.primary' => '#1c1b1f', 'text.muted' => '#585858', 'text.accent' => '#ff7f00', 'text.inverse' => '#ffffff', 'color.accent' => '#ff7f00', 'border.default' => '#e6e2d8',
    'font.heading' => SERIF, 'font.body' => SANS, 'text.body' => '#585858', 'text.heading' => '#1c1b1f',
    'type.body_size' => '17px', 'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0', 'layout.content' => '1300px',
]);
Database::setSetting('site_name', "Chef Gregory's", $tid);
Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '1', $tid);
Database::setSetting('studio_seo_title_format', '%title%', $tid);
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant:wght@400;500;600;700&family=Cormorant+Garamond:ital,wght@0,400;0,500;1,400;1,500&family=Italianno&family=Rajdhani:wght@400;500;600;700&family=Heebo:wght@300;400;500;700&family=Lora:wght@400;500;600&family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400;500;700&family=Open+Sans:wght@400;600&family=Jost:wght@400;500;600&display=swap">';
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fonts, 'head'), $tid);
$css = StudioCodePolicy::prepareCustomCss((string) file_get_contents(__DIR__ . '/gap.css'));
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, $css['css'], $tid);

// ── Content types ──────────────────────────────────────────────────────────────
$type = static fn(string $key, string $plural, string $singular, string $route, string $icon, int $pos, array $columns, array $filters = []): array => [
    'key' => $key, 'singular' => $singular, 'plural' => $plural, 'route' => $route, 'is_system' => false, 'description' => '', 'show_in_menu' => true,
    'rewrite' => true, 'rewrite_slug' => trim($route, '/'), 'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => $icon, 'menu_position' => $pos, 'columns' => $columns, 'filters' => $filters,
];
PageMeta::savePostTypes($tid, [
    'service'   => $type('service', 'Services', 'Service', '/service', 'briefcase', 1, [['label' => '', 'source' => '_status', 'prefix' => '', 'suffix' => '', 'sortable' => true]]),
    'blog_post' => $type('blog_post', 'Blog Posts', 'Blog Post', '/blog-post', 'file-text', 2, [['label' => 'Published', 'source' => '_date', 'prefix' => '', 'suffix' => '', 'sortable' => true]]),
    'recipe'    => $type('recipe', 'Recipes', 'Recipe', '/recipe', 'book', 3, [['label' => 'Difficulty', 'source' => 'difficulty', 'prefix' => '', 'suffix' => '', 'sortable' => true], ['label' => 'Cook time', 'source' => 'cook_time', 'prefix' => '', 'suffix' => '', 'sortable' => false]], [['label' => 'Difficulty', 'source' => 'difficulty']]),
    'area'      => $type('area', 'Catering Areas', 'Catering Area', '/catering', 'map-pin', 4, [['label' => 'Address', 'source' => '_slug', 'prefix' => '', 'suffix' => '', 'sortable' => true]]),
]);
$field = static fn(string $type, string $key, string $label, string $kind = 'text', array $options = []): array => ['id' => 'f_' . substr(md5($type . $key), 0, 8), 'post_type' => $type, 'label' => $label, 'key' => $key, 'type' => $kind, 'required' => false, 'placeholder' => '', 'help' => '', 'options' => $options];
$rep = static fn(string ...$subs): array => ['subfields' => array_map(static function (string $s): array { [$key, $label, $type] = explode(':', $s); return ['key' => $key, 'label' => $label, 'type' => $type]; }, $subs)];
$faq = $rep('question:Question:text', 'answer:Answer:richtext');
PageMeta::saveFields($tid, [
    $field('service', 'service_heading', 'Service heading'), $field('service', 'service_description', 'Service description', 'richtext'), $field('service', 'includes', 'Includes', 'repeater', $rep('include_item:Include item:text')), $field('service', 'faq', 'FAQ', 'repeater', $faq), $field('service', 'areas_served', 'Areas served'), $field('service', 'hero_image', 'Hero image', 'image'),
    $field('blog_post', 'hero_image', 'Hero image', 'image'), $field('blog_post', 'date_label', 'Date label'), $field('blog_post', 'lede', 'Lede', 'textarea'), $field('blog_post', 'body', 'Body', 'richtext'), $field('blog_post', 'toc', 'In this article', 'list'),
    $field('recipe', 'hero_image', 'Hero image', 'image'), $field('recipe', 'eyebrow', 'Eyebrow'), $field('recipe', 'prep_time', 'Prep time'), $field('recipe', 'cook_time', 'Cook time'), $field('recipe', 'serves', 'Serves'), $field('recipe', 'temperature', 'Temperature'),
    $field('recipe', 'difficulty', 'Difficulty', 'select', ['choices' => [['value' => 'Easy', 'label' => 'Easy'], ['value' => 'Intermediate', 'label' => 'Intermediate'], ['value' => 'Advanced', 'label' => 'Advanced']]]), $field('recipe', 'ingredients', 'Ingredients', 'repeater', $rep('ingredient:Ingredient:text')), $field('recipe', 'body', 'Instructions', 'richtext'),
    $field('area', 'hero_image', 'Hero image', 'image'), $field('area', 'eyebrow', 'Eyebrow'), $field('area', 'area_heading', 'Heading'), $field('area', 'area_description', 'Description', 'richtext'), $field('area', 'faq', 'FAQ', 'repeater', $faq),
]);
PageRoutes::forget($tid);
say('  + content types');

// ── Items ──────────────────────────────────────────────────────────────────────
$tops = static fn(array $list, array $mlist, int $from, ?int $to = null): array => array_map(static fn(array $t, ?array $m): array => top($t, $m), array_slice($list, $from, $to), array_slice($mlist, $from, $to) + array_fill(0, count(array_slice($list, $from, $to)), null));

Database::setSetting('studio_redirects', '[]', $tid);

// ── Pages ──────────────────────────────────────────────────────────────────────
$pages = array_values(array_filter(array_keys($CAP), static fn($u) => !preg_match('~^/(service/.+|blog-post/.+|recipe/.+|catering/.+|catering-in-.+|paso-robles-wedding-catering/)$~', $u)));
foreach ($pages as $u) {
    if ($u === '/thanksgiving-menu/' || in_array($u, ['/', '/services/', '/service/', '/about-us/', '/contact/', '/catering/', '/gallery/', '/plated-and-styled/', '/items-for-catered-events/', '/canapes/', '/corporate-breakfast-continental/', '/ginger-soy-dressing/', '/gourmet-me-now/', '/blog-post/', '/media-press/', '/recipe/', '/contact/help-us-help-you/', '/cooking-classes/', '/consulting/', '/holiday-services/'], true)) { continue; }
    $x = $MAIN($u);
    $slug = $u === '/' ? 'home' : basename($u);
    $title = trim(html_entity_decode($WP[$u]['title']['rendered'] ?? $PTITLE($u)));
    build_page($slug, $title ?: ucfirst($slug), 'page', $u === '/' ? 'homepage' : 'standalone', $tops($x['d'], $x['m'], 0), ['seo_title' => $CAP[$u]['d']['title'], 'seo_desc' => $meta($u), 'image' => $heroFile($x['d'][0] ?? ['b' => []])]);
}

require __DIR__ . '/thanksgiving.php';

require __DIR__ . '/design.php';

// Addresses that follow no one pattern stay exactly as on the live site.
$own = ['' . (int) Database::value("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'help-us-help-you' AND page_type = 'page' AND status <> 'archived'", [$tid]) => 'contact'];
foreach ($AREAPAGES as $slug => $flat) { if ($flat) { $own[(string) $AREAIDS[$slug]] = ''; } }
Database::setSetting(PageRoutes::SETTING_PAGE_PREFIXES, json_encode($own), $tid);
PageRoutes::forget($tid);
