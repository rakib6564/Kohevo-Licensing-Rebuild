<?php
// Chef Gregory's recipes: the Recipe content type, its Single template and the recipe items. Only touches what it
// builds (other content types and their fields are kept), so it is safe on a site someone has edited.
//
//   cd <site> && php <bundle>/build/recipes.php
//
// Recipe text comes from Gregory's own documents (build/recipe-*.json) and is published as he wrote it.
declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/content.php';
$BUNDLE = dirname(__DIR__);

// ── Photos ─────────────────────────────────────────────────────────────────────
$recipes = array_map(static fn(string $f): array => json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR), glob(__DIR__ . '/recipe-*.json') ?: []);
$dst = getcwd() . '/uploads/media/gq';
foreach ($recipes as $r) {
    foreach ($r['photos'] as $name => $alt) {
        if (!is_file("{$dst}/{$name}")) {
            if (!is_file("{$BUNDLE}/media/{$name}")) { throw new RuntimeException("Photo {$name} is missing from media/"); }
            copy("{$BUNDLE}/media/{$name}", "{$dst}/{$name}");
        }
        $size = getimagesize("{$dst}/{$name}") ?: [];
        $id = Media::register(MEDIA_DIR . $name, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $name]);
        Database::query('UPDATE media_files SET alt_text = ? WHERE id = ?', [$alt, $id]);
    }
}
say('  + recipe photos');

// ── Content type ───────────────────────────────────────────────────────────────
$types = json_decode((string) Database::setting('studio_custom_post_types', $tid), true) ?: [];
$types['recipe'] = ['key' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes', 'route' => '/recipes', 'is_system' => false, 'description' => '', 'show_in_menu' => true,
    'rewrite' => true, 'rewrite_slug' => 'recipes', 'hide_field_names' => false, 'edit_link' => true, 'menu_icon' => 'chef-hat', 'menu_position' => 3, 'columns' => [], 'filters' => []];
PageMeta::savePostTypes($tid, $types);
$field = static fn(string $key, string $label, string $kind = 'text', array $options = []): array => ['id' => 'f_' . substr(md5('recipe' . $key), 0, 8), 'post_type' => 'recipe', 'label' => $label, 'key' => $key, 'type' => $kind, 'required' => false, 'placeholder' => '', 'help' => '', 'options' => $options];
PageMeta::replaceFieldsFor($tid, 'recipe', [
    $field('eyebrow', 'Style'), $field('temperature', 'Pit temperature'), $field('hero_image', 'Photo', 'image'),
    $field('ingredients', 'Ingredients', 'repeater', ['subfields' => [['key' => 'ingredient', 'label' => 'Ingredient', 'type' => 'text']]]),
    $field('body', 'Instructions', 'richtext'),
]);
PageRoutes::forget($tid);
say('  + recipe type');

// ── Single template ────────────────────────────────────────────────────────────
TEMPLATE('template-recipe', 'Single: Recipe', 'single', 'recipe', [
    SEC('Recipe', [
        ROW([
            W(VBOX([
                FV('eyebrow', ['color' => MUSTARD] + TYPE(COND, '20px', '700', ['transform' => 'uppercase', 'letter_spacing' => '2.5px'])),
                B('theme.post_title', ['level' => 'h1'], [], ['color' => '#ffffff', 'typography' => ['size' => '56px', 'font_family' => SLAB, 'weight' => '400', 'line_height' => '1.06', 'transform' => 'uppercase']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => '34px']]]]]),
                ROW([P('Pit temp', MUSTARD, '19px', 'left', '700'), FV('temperature', ['color' => '#ffffff'] + TYPE(COND, '24px', '700'))], '10px', ['align' => 'center'], [], [], ['when_field' => 'temperature']),
            ], '14px'), '50%'),
            W(VBOX([FV('hero_image', [], ['when_field' => 'hero_image'])], '0px', [], ['classNames' => ['gq-item-img']]), '46%'),
        ], '40px', ['justify' => 'between', 'align' => 'center'], ['layout' => ['direction' => 'column', 'gap' => '28px']]),
    ], SOOT, '64px', '36px'),
    SEC('Method', [
        ROW([
            // Font settings sit on a wrapper: on the field block itself they force every heading inside to body size.
            W(VBOX([FV('body', [], ['classNames' => ['gq-prose', 'gq-recipe']])], '0px', ['color' => SMOKE] + TYPE(BODY, '18px')), '60%'),
            W(VBOX([
                H('Ingredients', 'h2', '32px', '28px'),
                B('theme.repeater', ['field' => 'ingredients', 'display' => 'list', 'title_field' => 'ingredient'], [], ['color' => SOOT] + TYPE(BODY, '17px', '500'), ['classNames' => ['gq-ingredients']]),
                BTNS([BTN('Order BBQ', ORDER)]),
            ], '16px', ['background' => ['color' => LIGHT], 'border' => ['width' => '4px', 'style' => 'solid', 'color' => SOOT], 'padding' => ['top' => '28px', 'right' => '28px', 'bottom' => '28px', 'left' => '28px']]), '34%'),
        ], '48px', ['justify' => 'between', 'align' => 'start'], ['layout' => ['direction' => 'column', 'gap' => '32px']]),
    ], PAPER, '72px', '40px'),
]);

// ── Items ──────────────────────────────────────────────────────────────────────
foreach ($recipes as $r) {
    build_page($r['slug'], $r['title'], 'page', 'standalone', [], ['type' => 'recipe', 'image' => $r['hero_image'], 'seo_title' => $r['seo_title'], 'seo_desc' => $r['seo_desc'],
        'settings' => ['excerpt' => $r['excerpt']],
        'fields' => ['eyebrow' => $r['eyebrow'], 'temperature' => $r['temperature'], 'hero_image' => MEDIA_DIR . $r['hero_image'],
            'ingredients' => array_map(static fn(string $i): array => ['ingredient' => $i], $r['ingredients']), 'body' => str_replace('{MEDIA}', MEDIA_DIR, $r['body'])],
    ]);
}
// ── Styles ─────────────────────────────────────────────────────────────────────
// site.php writes design.css as the whole custom stylesheet; the recipe rules go after it. Saving strips comments,
// so an earlier copy of the rules is found by its own text and replaced.
$rules = StudioCodePolicy::prepareCustomCss((string) file_get_contents(__DIR__ . '/recipes.css'))['css'];
$css = trim(str_replace($rules, '', (string) Database::setting(StudioCodePolicy::SETTING_CUSTOM_CSS, $tid)));
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, StudioCodePolicy::prepareCustomCss($css . "\n" . $rules)['css'], $tid);
say('  + recipe styles');

Database::query('DELETE FROM studiobuilder_compilations WHERE tenant_id = ?', [$tid]);
say('done');
