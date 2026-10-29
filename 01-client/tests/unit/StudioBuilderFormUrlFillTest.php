<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — form fields filled from the page address (`url_param`, `url_values`).
 *
 * The page is cached, so the renderer only writes what to fill from (data-sb-url-param) and the value map
 * (data-sb-url-values); the runtime does the filling. Verifies the parameter names are kept plain, a list field only maps
 * to its own options (an unknown value keeps its default), the map is escaped into the attribute, and a field without an
 * address fill renders exactly as before.
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
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormFieldRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Tenancy\TenantContext;

function sbuf_render(array $props): string
{
    $block = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.form_field', 'version' => 1, 'props' => $props];
    $scope = new BlockRenderScope($block, RenderContext::forPublic(101, new SiteContext('https://example.test', 'Test')), new CoreMediaResolver(new TenantContext()),
        new ResolvedTheme('default', []), new RenderCollector(), '', []);
    return (new FormFieldRenderer())->render($scope);
}

/** The value map a rendered field carries, decoded. @return array<string, string>|null */
function sbuf_map(string $html): ?array
{
    return preg_match('/data-sb-url-values="([^"]*)"/', $html, $m) === 1 ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : null;
}

unit('form url fill: a list field maps address values to its own options only', function (): void {
    $fill = FormFieldRenderer::urlFill(' reason , bad name!, reason ', "cours = Question sur un cours\nDON=Question relative à un don\nnope=Not an option\nno equals sign", ['Information', 'Question sur un cours', 'Question relative à un don']);
    assert_eq(['params' => ['reason'], 'values' => ['cours' => 'Question sur un cours', 'don' => 'Question relative à un don']], $fill, 'plain names only, once; keys lower-cased; options only');
    assert_true(FormFieldRenderer::urlFill('', 'a=b', null) === null, 'no parameter, no fill');
    assert_true(FormFieldRenderer::urlFill('reason', 'x=Not an option', ['A']) === null, 'a list field with nothing to map is not filled');
    assert_eq(['params' => ['activity', 'subject'], 'values' => []], FormFieldRenderer::urlFill('activity,subject', '', null), 'a text field takes values as typed');
});

unit('form url fill: the renderer writes the fill for selects, radios and text, escaped', function (): void {
    $select = sbuf_render(['name' => 'motif', 'label' => 'Motif', 'field_type' => 'select', 'options' => 'Information, Question sur un cours',
        'default_value' => 'Information', 'url_param' => 'reason', 'url_values' => "cours=Question sur un cours"]);
    assert_true(str_contains($select, '<select name="motif"') && str_contains($select, 'data-sb-url-param="reason"'), $select);
    assert_eq(['cours' => 'Question sur un cours'], sbuf_map($select));
    assert_true(str_contains($select, '<option value="Information" selected>'), 'the default stays the fallback');

    $radio = sbuf_render(['name' => 'tier', 'label' => 'Tier', 'field_type' => 'radio', 'options' => 'Gold, Silver', 'url_param' => 'plan', 'url_values' => 'g=Gold']);
    assert_true(preg_match('/<fieldset[^>]*data-sb-url-param="plan"/', $radio) === 1, 'a radio group carries it on its fieldset');

    $text = sbuf_render(['name' => 'objet', 'label' => 'Objet', 'field_type' => 'text', 'url_param' => 'activity, subject', 'url_values' => 'x"y=<b>"Q"</b>']);
    assert_true(str_contains($text, 'data-sb-url-param="activity,subject"'), $text);
    assert_true(!str_contains($text, '<b>') && sbuf_map($text) === ['x"y' => '<b>"Q"</b>'], 'the map is escaped and round-trips');

    $hidden = sbuf_render(['name' => 'source', 'label' => 'Source', 'field_type' => 'hidden', 'url_param' => 'utm_source']);
    assert_true(str_contains($hidden, 'type="hidden"') && str_contains($hidden, 'data-sb-url-param="utm_source"'), $hidden);

    $plain = sbuf_render(['name' => 'email', 'label' => 'E-mail', 'field_type' => 'email']);
    assert_true(!str_contains($plain, 'data-sb-url'), 'no address fill: nothing extra');
    assert_true(!str_contains(sbuf_render(['name' => 'd', 'label' => 'Date', 'field_type' => 'date', 'url_param' => 'd']), 'data-sb-url'), 'a date is never filled from the address');
});

unit('form url fill: the definition accepts plain parameter names only', function (): void {
    $schema = ModuleBlockDefinitions::studioRegistry()->get('core.form_field')->schema();
    $base = ['name' => 'motif', 'label' => 'Motif', 'field_type' => 'select'];
    assert_true($schema->validate($base + ['url_param' => 'reason, activity', 'url_values' => "cours=Cours\ndon=Don"])->isValid());
    assert_false($schema->validate($base + ['url_param' => 'reason"><script>'])->isValid(), 'markup in a parameter name is refused');
});
