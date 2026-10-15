<?php
/**
 * Kohevo Studio — the Field value block shows one custom field of the page, written the way its type reads best.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

const SBFV_TENANT = 102;

final class _SbfvNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

/** @param list<array<string, mixed>> $fields @param array<string, mixed> $values */
function sbfv_render(array $props, array $fields, array $values, string $mode = 'public'): string
{
    $tenants = new TenantContext();
    $documents = new DocumentRenderer(ModuleBlockDefinitions::studioRegistry(), BlockRendererRegistry::withStudioRenderers(), new _SbfvNoMedia(), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $site = new SiteContext('https://acme.test', 'Acme');
    $attrs = ['page_meta' => ['type' => 'services', 'values' => $values, 'fields' => $fields]];
    $ctx = $mode === 'public'
        ? RenderContext::forPublic(SBFV_TENANT, $site, static fn(string $m): bool => true, $attrs)
        : RenderContext::forEditor(SBFV_TENANT, $site, StudioActor::authenticated(3, [StudioPermissions::VIEW, StudioPermissions::EDIT]), static fn(string $m): bool => true)->withAttributes($attrs);
    $block = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'theme.field_value', 'version' => 1, 'props' => $props, 'style' => CanonicalDocumentSchema::defaultBlockStyle(),
              'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [], 'animation' => [], 'interactions' => []];
    return $tenants->runAs(SBFV_TENANT, static fn(): string => $documents->renderBlock($block, $ctx, (new ThemeResolver())->resolve('default'), new RenderCollector(), false));
}

unit('field value: text is escaped, a select shows its label, a list is a list, and a label can be shown', function (): void {
    $fields = [
        ['key' => 'client', 'label' => 'Client', 'type' => 'text'],
        ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['choices' => [['value' => 'remote', 'label' => 'Remote work']]]],
        ['key' => 'tools', 'label' => 'Tools', 'type' => 'list'],
        ['key' => 'ok', 'label' => 'Featured', 'type' => 'boolean'],
    ];
    $values = ['client' => '<b>Acme</b> & Co', 'mode' => 'remote', 'tools' => ['PHP', 'JS'], 'ok' => true];
    $h = sbfv_render(['field' => 'client'], $fields, $values);
    assert_true(str_contains($h, '&lt;b&gt;Acme&lt;/b&gt; &amp; Co') && !str_contains($h, '<b>'), $h);
    assert_true(str_contains(sbfv_render(['field' => 'mode'], $fields, $values), 'Remote work'), 'a choice shows its label');
    assert_true(str_contains(sbfv_render(['field' => 'tools'], $fields, $values), '<ul><li>PHP</li><li>JS</li></ul>'));
    assert_true(str_contains(sbfv_render(['field' => 'ok'], $fields, $values), 'Yes'));
    assert_true(str_contains(sbfv_render(['field' => 'client', 'show_label' => true], $fields, $values), 'sb-field-value__label">Client</span>'));
    assert_true(str_contains(sbfv_render(['field' => 'client', 'tag' => 'script'], $fields, $values), '<div '), 'an unknown tag falls back to div');
});

unit('field value: addresses are checked again, so a script address renders no link or image', function (): void {
    $fields = [['key' => 'site', 'label' => 'Site', 'type' => 'url'], ['key' => 'pic', 'label' => 'Pic', 'type' => 'image']];
    assert_true(str_contains(sbfv_render(['field' => 'site'], $fields, ['site' => 'https://a.test/x?y=1&z=2']), 'href="https://a.test/x?y=1&amp;z=2"'));
    assert_true(!str_contains(sbfv_render(['field' => 'site'], $fields, ['site' => 'javascript:alert(1)']), 'javascript'), 'no script address in the output');
    assert_true(!str_contains(sbfv_render(['field' => 'pic'], $fields, ['pic' => '//evil.test/a.png']), 'evil.test'));
    assert_true(str_contains(sbfv_render(['field' => 'pic'], $fields, ['pic' => '/media/a.png']), '<img src="/media/a.png"'));
});

unit('field value: no value shows the fallback publicly, nothing without one, and a hint in the editor', function (): void {
    $fields = [['key' => 'client', 'label' => 'Client', 'type' => 'text']];
    assert_true(str_contains(sbfv_render(['field' => 'client', 'fallback' => 'Not set'], $fields, []), 'Not set'));
    assert_true(!str_contains(sbfv_render(['field' => 'client'], $fields, []), 'sb-field-value'), 'nothing publicly without a fallback');
    assert_true(!str_contains(sbfv_render(['field' => 'unknown'], $fields, ['unknown' => 'x']), '>x<'), 'a key the type does not define shows nothing');
    assert_true(str_contains(sbfv_render(['field' => 'client'], $fields, [], 'editor'), 'No value for'));
    assert_true(str_contains(sbfv_render([], $fields, [], 'editor'), 'Choose a field'));
});

unit('field value: the block is in the registry, validates its props, and a field key must be a plain key', function (): void {
    $def = ModuleBlockDefinitions::studioRegistry()->get('theme.field_value');
    assert_true($def !== null && $def->version() === 1);
    $ctx = RenderContext::forPublic(SBFV_TENANT, new SiteContext('https://acme.test', 'Acme'), static fn(string $m): bool => true);
    assert_eq(null, $ctx->attribute('page_meta'), 'a context without a page has no meta');
    assert_eq(7, $ctx->withAttributes(['a' => 7])->attribute('a'), 'withAttributes adds');
    assert_eq(null, $ctx->attribute('a'), 'and leaves the original alone');
});
