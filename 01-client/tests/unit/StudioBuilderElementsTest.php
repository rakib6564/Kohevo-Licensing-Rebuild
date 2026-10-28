<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P2b elements: core.icon, core.list, core.quote, core.link.
 *
 * Dependency-free (no database). Drives the real pipeline (registry, normalizer, validator, compiler, renderers).
 * Verifies: registration and manifest metadata, starter content, exact markup, escaping of every author-supplied
 * string, unsafe URLs, unknown icon names and enum values, empty blocks rendering nothing, and determinism.
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
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

const SBEL_TENANT = 104;
const SBEL_TYPES  = ['core.icon', 'core.list', 'core.quote', 'core.link'];

final class _SbelNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @return array<string, mixed> */
function sbel_block(string $type, array $props = []): array
{
    return [
        'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
}

/** @return array<string, mixed> */
function sbel_doc(array $blocks): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Elements');
    $doc['sections'] = [[
        'blocks' => $blocks, 'global_ref' => null, 'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'Main',
        'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

/** Compile `$blocks` as a published page and return the body HTML. */
function sbel_render(array $blocks, string $baseUrl = 'https://acme.test'): string
{
    $tenants   = new TenantContext();
    $registry  = ModuleBlockDefinitions::studioRegistry();
    $renderers = BlockRendererRegistry::withStudioRenderers();
    $media     = new _SbelNoMedia();
    $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $compiler  = new StudioCompiler($tenants, $registry, $renderers, $documents, new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500']), new ChromeResolver(), $media);
    $site      = new SiteContext($baseUrl, 'Acme', 'https://acme.test/l.png', 'https://acme.test/f.png', 'en');
    $ctx       = RenderContext::forPublic(SBEL_TENANT, $site, static fn(): bool => true);
    $page      = new PageAddress(6, '11111111-2222-3333-4444-555555555557', 'Elements', 'elements', 'page', 'published', 'standalone', 9, 9);
    $doc       = sbel_doc($blocks);
    $compiled  = $tenants->runAs(SBEL_TENANT, static fn() => $compiler->compile($page, ['id' => 9, 'page_id' => 6, 'document_json' => CanonicalJson::encode($doc)], $ctx));
    return (string) $compiled->html;
}

unit('elements: the four types are registered with a title, description, category and icon', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $byType = [];
    foreach ($registry->editorManifests() as $m) {
        $byType[$m['type']] = $m;
    }
    foreach (SBEL_TYPES as $type) {
        assert_true($registry->has($type), "{$type} registered");
        assert_true(isset($byType[$type]), "{$type} in the manifest");
        foreach (['title', 'description', 'category', 'icon'] as $key) {
            assert_true(is_string($byType[$type][$key] ?? null) && $byType[$type][$key] !== '', "{$type} has {$key}");
        }
        assert_true($byType[$type]['allows_children'] === false, "{$type} is a leaf");
    }
});

unit('elements: starter content normalises in, so a fresh list and quote are visible', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $doc = DocumentNormalizer::normalize(sbel_doc([sbel_block('core.list'), sbel_block('core.quote'), sbel_block('core.icon')]), $registry);
    $byType = [];
    foreach ($doc['sections'][0]['blocks'] as $b) {
        $byType[$b['type']] = $b['props'];
    }
    assert_true(count($byType['core.list']['items']) === 3, 'list gets three starter items');
    assert_eq('bullet', $byType['core.list']['style']);
    assert_true($byType['core.quote']['text'] !== '', 'quote gets starter text');
    assert_eq('star', $byType['core.icon']['name']);
    assert_eq('md', $byType['core.icon']['size']);
});

unit('elements: validation rejects unknown icons, bad enums, oversize text and unknown props', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    assert_false($registry->get('core.icon')->validateProps(['name' => 'not-an-icon'])->isValid(), 'unknown icon name');
    assert_false($registry->get('core.icon')->validateProps(['size' => 'huge'])->isValid(), 'unknown size');
    assert_false($registry->get('core.icon')->validateProps(['name' => 'star', 'svg' => '<svg/>'])->isValid(), 'no raw svg prop');
    assert_false($registry->get('core.list')->validateProps(['style' => 'roman'])->isValid(), 'unknown list style');
    assert_false($registry->get('core.list')->validateProps(['items' => [['text' => str_repeat('a', 301)]]])->isValid(), 'list item too long');
    assert_false($registry->get('core.list')->validateProps(['items' => array_fill(0, 31, ['text' => 'x'])])->isValid(), 'too many list items');
    assert_false($registry->get('core.quote')->validateProps(['text' => str_repeat('q', 1001)])->isValid(), 'quote too long');
    assert_false($registry->get('core.link')->validateProps([])->isValid(), 'a link needs its link');
    assert_true($registry->get('core.link')->validateProps(['link' => ['href' => 'https://a.test', 'label' => 'Go', 'target' => '_self']])->isValid(), 'a valid link passes');
    foreach (IconLibrary::names() as $name) {
        assert_true($registry->get('core.icon')->validateProps(['name' => $name])->isValid(), "icon {$name} validates");
    }
});

unit('elements: core.icon renders only constant markup, with a11y rules', function (): void {
    $html = sbel_render([sbel_block('core.icon', ['name' => 'heart', 'size' => 'lg', 'align' => 'center'])]);
    assert_true(str_contains($html, 'class="sb-icon sb-icon--lg sb-icon--center"'), 'size and alignment classes');
    assert_true(str_contains($html, IconLibrary::markup('heart')), 'the heart markup');
    assert_true(str_contains($html, 'aria-hidden="true"'), 'decorative by default');
    assert_false(str_contains($html, 'role="img"'), 'no role when decorative');

    $labelled = sbel_render([sbel_block('core.icon', ['name' => 'phone', 'label' => 'Call "us" <b>'])]);
    assert_true(str_contains($labelled, 'role="img" aria-label="Call &quot;us&quot; &lt;b&gt;"'), 'labelled icons expose an escaped name');
    assert_false(str_contains($labelled, 'aria-hidden'), 'a labelled icon is not hidden');
});

unit('elements: core.icon with a name that is not in the library renders nothing', function (): void {
    $html = sbel_render([sbel_block('core.icon', ['name' => '"><script>alert(1)</script>'])]);
    assert_false(str_contains($html, '<script>alert'), 'never echoed');
    assert_false(str_contains($html, 'sb-icon__svg'), 'no svg for an unknown name');
});

unit('elements: core.list renders ul, ol, the check and plain styles, and escapes every item', function (): void {
    $items = [['text' => 'One <b>&</b>'], ['text' => 'Two lines']];
    $ul = sbel_render([sbel_block('core.list', ['style' => 'bullet', 'items' => $items])]);
    assert_true(str_contains($ul, '<ul class="sb-list sb-list--bullet">'), 'bullet is a ul');
    assert_true(str_contains($ul, '<li class="sb-list__item">One &lt;b&gt;&amp;&lt;/b&gt;</li>'), 'item escaped');
    assert_eq(2, substr_count($ul, 'sb-list__item'), 'one li per item');

    $ol = sbel_render([sbel_block('core.list', ['style' => 'number', 'items' => $items])]);
    assert_true(str_contains($ol, '<ol class="sb-list sb-list--number">'), 'number is an ol');
    assert_true(str_contains(sbel_render([sbel_block('core.list', ['style' => 'check', 'items' => $items])]), 'sb-list--check'), 'check style class');
    assert_false(str_contains(sbel_render([sbel_block('core.list', ['items' => [['text' => '']]])]), 'sb-list__item'), 'a blank item is never emitted');
});

unit('elements: core.quote escapes text, author and role, and omits an empty caption', function (): void {
    $html = sbel_render([sbel_block('core.quote', ['text' => 'It <i>works</i>', 'author' => 'A & B', 'role' => '<CEO>', 'align' => 'center'])]);
    assert_true(str_contains($html, '<figure class="sb-quote sb-quote--center">'), 'figure with alignment');
    assert_true(str_contains($html, '<blockquote class="sb-quote__text"><p>It &lt;i&gt;works&lt;/i&gt;</p></blockquote>'), 'quote text escaped');
    assert_true(str_contains($html, '<span class="sb-quote__author">A &amp; B</span>'), 'author escaped');
    assert_true(str_contains($html, '<span class="sb-quote__role">&lt;CEO&gt;</span>'), 'role escaped');

    $bare = sbel_render([sbel_block('core.quote', ['text' => 'Just words'])]);
    assert_false(str_contains($bare, 'figcaption'), 'no caption without author or role');
});

unit('elements: core.link renders a safe anchor and refuses unsafe schemes', function (): void {
    $html = sbel_render([sbel_block('core.link', ['link' => ['href' => 'https://acme.test/a?b=1&c=2', 'label' => 'Read <more>', 'target' => '_blank'], 'style' => 'arrow', 'align' => 'right'])]);
    assert_true(str_contains($html, '<p class="sb-link-row sb-link-row--right">'), 'row alignment');
    assert_true(str_contains($html, 'class="sb-link sb-link--arrow"'), 'style class');
    assert_true(str_contains($html, 'href="https://acme.test/a?b=1&amp;c=2"'), 'href escaped');
    assert_true(str_contains($html, 'target="_blank"') && str_contains($html, 'rel="noopener noreferrer"'), 'new-tab links get rel');
    assert_true(str_contains($html, '>Read &lt;more&gt;</a>'), 'label escaped');

    $js = sbel_render([sbel_block('core.link', ['link' => ['href' => 'javascript:alert(1)', 'label' => 'Click', 'target' => '_self']])]);
    assert_false(str_contains($js, 'javascript:'), 'a javascript: href never reaches the page');
    assert_false(str_contains($js, '<a class="sb-link'), 'and it is not an anchor');
});

unit('elements: output is deterministic and the document validates', function (): void {
    $blocks = [
        sbel_block('core.icon', ['name' => 'check']),
        sbel_block('core.list', ['items' => [['text' => 'A'], ['text' => 'B']]]),
        sbel_block('core.quote', ['text' => 'Q', 'author' => 'Me']),
        sbel_block('core.link', ['link' => ['href' => 'https://acme.test', 'label' => 'Home', 'target' => '_self']]),
    ];
    assert_eq(sbel_render($blocks), sbel_render($blocks), 'same input, same bytes');
    $doc = DocumentNormalizer::normalize(sbel_doc($blocks), ModuleBlockDefinitions::studioRegistry());
    assert_true(DocumentValidator::validate($doc, ModuleBlockDefinitions::studioRegistry())->isValid(), 'a document of the four elements validates');
});

// ── P2b part 2: Card, Table, Countdown ───────────────────────────────────────

const SBEL_TYPES2 = ['core.card', 'core.table', 'core.countdown'];

/** The countdown element the browser test loads, pinned here so the renderer and the runtime cannot drift apart. */
const SBEL_COUNTDOWN_PROPS = ['target' => '2030-01-01T00:00:00+01:00', 'label' => 'Doors open in', 'done_text' => 'We are live'];

unit('elements 2: card, table and countdown are registered; only the card holds children', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $byType = [];
    foreach ($registry->editorManifests() as $m) {
        $byType[$m['type']] = $m;
    }
    foreach (SBEL_TYPES2 as $type) {
        assert_true($registry->has($type), "{$type} registered");
        foreach (['title', 'description', 'category', 'icon'] as $key) {
            assert_true(is_string($byType[$type][$key] ?? null) && $byType[$type][$key] !== '', "{$type} has {$key}");
        }
    }
    assert_true($byType['core.card']['allows_children'] === true, 'a card holds blocks');
    assert_true($byType['core.table']['allows_children'] === false && $byType['core.countdown']['allows_children'] === false, 'table and countdown are leaves');
});

unit('elements 2: card renders its children inside a padded surface and counts as a nesting level', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $inner = sbel_block('core.heading', ['text' => 'Inside <b>', 'level' => 'h2']);
    $card = sbel_block('core.card', ['variant' => 'shadow', 'padding' => 'lg']);
    $card['children'] = [$inner];
    $html = sbel_render([$card]);
    assert_true(str_contains($html, '<div class="sb-card sb-card--shadow sb-card--pad-lg">'), 'variant and padding classes');
    assert_true(str_contains($html, 'Inside &lt;b&gt;'), 'child rendered and escaped');
    assert_true(strpos($html, 'sb-card') < strpos($html, 'Inside'), 'child sits inside the card');

    $nest = static function (int $cards) use ($registry): array {
        $node = sbel_block('core.heading', ['text' => 'x', 'level' => 'h2']);
        for ($i = 0; $i < $cards; $i++) {
            $c = sbel_block('core.card');
            $c['children'] = [$node];
            $node = $c;
        }
        return sbel_doc([$node]);
    };
    assert_true(DocumentValidator::validate($nest(CanonicalDocumentSchema::MAX_NESTING_DEPTH - 1), $registry)->isValid(), 'cards up to the limit validate');
    assert_false(DocumentValidator::validate($nest(CanonicalDocumentSchema::MAX_NESTING_DEPTH), $registry)->isValid(), 'one level more does not');
    assert_false($registry->get('core.card')->validateProps(['variant' => 'neon'])->isValid(), 'unknown variant rejected');
});

unit('elements 2: table renders header, rows and caption; ragged rows are padded and trimmed; every cell is escaped', function (): void {
    $html = sbel_render([sbel_block('core.table', [
        'caption' => 'Pri<ces>', 'header' => 'Plan | Price',
        'rows' => [['cells' => 'A & B | $9 | extra | cells'], ['cells' => 'Solo'], ['cells' => '  ']],
    ])]);
    assert_true(str_contains($html, '<caption class="sb-table__caption">Pri&lt;ces&gt;</caption>'), 'caption escaped');
    assert_true(str_contains($html, '<th scope="col">Plan</th><th scope="col">Price</th>'), 'header cells');
    assert_true(str_contains($html, '<td>A &amp; B</td><td>$9</td></tr>'), 'a long row is trimmed to two columns');
    assert_true(str_contains($html, '<td>Solo</td><td></td></tr>'), 'a short row is padded');
    assert_eq(2, substr_count($html, '<td>Solo</td>') + substr_count($html, '<td>A &amp; B</td>'), 'one body row each');
    assert_eq(2, substr_count($html, '<tr><td>'), 'a blank row is dropped');
    assert_true(str_contains($html, 'sb-table-wrap sb-table-wrap--striped'), 'striped by default');

    $headless = sbel_render([sbel_block('core.table', ['header' => '', 'striped' => false, 'rows' => [['cells' => 'a | b | c']]])]);
    assert_false(str_contains($headless, '<thead>'), 'no header, no thead');
    assert_true(str_contains($headless, '<td>c</td>'), 'columns follow the widest row');
    assert_false(str_contains($headless, '--striped'), 'striping can be turned off');

    $wide = sbel_render([sbel_block('core.table', ['header' => implode('|', range(1, 12)), 'rows' => []])]);
    assert_eq(8, substr_count($wide, '<th '), 'at most eight columns');
    assert_false(str_contains(sbel_render([sbel_block('core.table', ['header' => '', 'rows' => []])]), 'sb-table'), 'an empty table renders nothing');
});

unit('elements 2: countdown output carries the target and never depends on now', function (): void {
    $block = sbel_block('core.countdown', SBEL_COUNTDOWN_PROPS);
    $html = sbel_render([$block]);
    assert_true(str_contains($html, 'data-sb-countdown="2029-12-31T23:00:00Z"'), 'target normalised to UTC');
    assert_true(str_contains($html, '<time datetime="2029-12-31T23:00:00Z">2029-12-31 23:00 UTC</time>'), 'the no-JS date line');
    foreach (['d', 'h', 'm', 's'] as $k) {
        assert_true(str_contains($html, 'data-sb-cd="' . $k . '"'), "unit {$k}");
    }
    assert_true(str_contains($html, '<p class="sb-countdown__label">Doors open in</p>'), 'label');
    assert_true(str_contains($html, '<p class="sb-countdown__done">We are live</p>'), 'done text (shown by CSS once finished)');
    assert_eq(sbel_render([$block]), sbel_render([$block]), 'deterministic');

    $registry = ModuleBlockDefinitions::studioRegistry();
    foreach (['tomorrow', '2030-01-01', '2030-01-01T00:00:00', '2030-13-45T99:99:99Z', '<script>'] as $bad) {
        assert_false($registry->get('core.countdown')->validateProps(['target' => $bad])->isValid(), "target '{$bad}' is rejected");
    }
    assert_true($registry->get('core.countdown')->validateProps(['target' => '2030-01-01T00:00:00Z'])->isValid(), 'a UTC target is valid');
    assert_false(str_contains(sbel_render([sbel_block('core.countdown', ['target' => '2030-02-31T00:00:00Z'])]), 'sb-countdown'), 'an impossible calendar date is not rendered');
});

unit('elements 2: the countdown markup matches the browser fixture and the runtime hooks', function (): void {
    $html = sbel_render([sbel_block('core.countdown', SBEL_COUNTDOWN_PROPS)]);
    preg_match('#<div class="sb-countdown".*?</div></div>(?:<p class="sb-countdown__done">.*?</p>)?</div>#s', $html, $m);
    assert_true(isset($m[0]), 'the block markup is extractable');
    $fixture = __DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/countdown.html';
    if (getenv('SBEL_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $m[0] . "\n");
    }
    assert_eq(trim((string) file_get_contents($fixture)), $m[0], 'ui/tests/fixtures/countdown.html is stale — regenerate with SBEL_WRITE_FIXTURE=1');
    $runtime = (string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/assets/public/studio-runtime.js');
    foreach (['[data-sb-countdown]', 'data-sb-cd', 'data-sb-live', 'data-sb-finished'] as $hook) {
        assert_true(str_contains($runtime, $hook), "the runtime handles {$hook}");
    }
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-countdown[data-sb-live]') && str_contains($css, '[data-sb-finished] .sb-countdown__done'), 'the stylesheet reveals units and the done text by the attributes the runtime sets');
});

// ── Divider and Spacer ───────────────────────────────────────────────────────

unit('divider and spacer: registered as layout leaves with a title, description and a drawable icon', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $byType = [];
    foreach ($registry->editorManifests() as $m) {
        $byType[$m['type']] = $m;
    }
    foreach (['core.divider', 'core.spacer'] as $type) {
        assert_true($registry->has($type), "{$type} registered");
        assert_eq('layout', $byType[$type]['category'], "{$type} is in the Layout group");
        assert_true($byType[$type]['allows_children'] === false, "{$type} holds nothing");
    }
    assert_eq('divider', $byType['core.divider']['icon']);
    assert_eq('spacer', $byType['core.spacer']['icon']);
});

unit('divider: renders an hr with its style, thickness, width and alignment, and the defaults when nothing is set', function (): void {
    assert_true(str_contains(sbel_render([sbel_block('core.divider', [])]), '<hr class="sb-divider sb-divider--solid sb-divider--thin sb-divider--w-full sb-divider--center">'), 'the defaults');
    $html = sbel_render([sbel_block('core.divider', ['style' => 'dashed', 'weight' => 'thick', 'width' => 'short', 'align' => 'left'])]);
    assert_true(str_contains($html, '<hr class="sb-divider sb-divider--dashed sb-divider--thick sb-divider--w-short sb-divider--left">'), 'every choice reaches the markup');
    assert_eq(sbel_render([sbel_block('core.divider', ['style' => 'dotted'])]), sbel_render([sbel_block('core.divider', ['style' => 'dotted'])]), 'deterministic');
});

unit('divider: only the allowlisted words are accepted, and a stored value outside them is never rendered as a divider or echoed', function (): void {
    $block = ModuleBlockDefinitions::studioRegistry()->get('core.divider');
    foreach ([['style', 'wavy'], ['weight', 'huge'], ['width', '80%'], ['align', 'justify'], ['style', '"><script>x</script>']] as [$key, $bad]) {
        assert_false($block->validateProps([$key => $bad])->isValid(), "{$key} = {$bad} is refused");
    }
    assert_true($block->validateProps(['style' => 'dotted', 'weight' => 'medium', 'width' => 'narrow', 'align' => 'right'])->isValid(), 'valid choices pass');
    $html = sbel_render([sbel_block('core.divider', ['style' => '"><script>x</script>', 'weight' => 'huge'])]);
    assert_false(str_contains($html, '<script>x'), 'a bad value is never echoed');
    assert_false(str_contains($html, '<hr'), 'a block with a bad value is not rendered as a divider (the document check refuses it)');
});

unit('spacer: renders an empty, hidden block of the chosen size, md by default, and refuses other sizes', function (): void {
    assert_true(str_contains(sbel_render([sbel_block('core.spacer', [])]), '<div class="sb-spacer sb-spacer--md" aria-hidden="true"></div>'), 'the default');
    foreach (['xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $size) {
        assert_true(str_contains(sbel_render([sbel_block('core.spacer', ['size' => $size])]), 'class="sb-spacer sb-spacer--' . $size . '"'), "size {$size}");
    }
    $block = ModuleBlockDefinitions::studioRegistry()->get('core.spacer');
    foreach (['3xl', '40px', '"><b>'] as $bad) {
        assert_false($block->validateProps(['size' => $bad])->isValid(), "size {$bad} is refused");
    }
    assert_false(str_contains(sbel_render([sbel_block('core.spacer', ['size' => '999'])]), 'sb-spacer'), 'a stored value outside the words is not rendered as a spacer');
});

unit('divider and spacer: the stylesheet has a rule for every word, the spacer shrinks on a phone, and the divider takes the theme border colour', function (): void {
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    foreach (\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\DividerRenderer::STYLES as $v) {
        assert_true(str_contains($css, ".sb-divider--{$v}{border-top-style:{$v}}"), "divider style {$v}");
    }
    foreach (\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\DividerRenderer::WIDTHS as $v) {
        assert_true(str_contains($css, ".sb-divider--w-{$v}{width:"), "divider width {$v}");
    }
    foreach (\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SpacerRenderer::SIZES as $v) {
        assert_true(str_contains($css, ".sb-spacer--{$v}{height:"), "spacer size {$v}");
        assert_true(str_contains($css, "@media (max-width:767.98px){") && substr_count($css, ".sb-spacer--{$v}{height:") === 2, "spacer {$v} has a desktop and a phone height");
    }
    assert_true(str_contains($css, ':where(.sb-block--core-divider){border-color:var(--sb-border-default)}'), 'zero-specificity default, so a border colour on the block wins');
    assert_true(str_contains($css, 'border-top-color:inherit'), 'the line takes the block\'s border colour');
});

unit('nav menu: registered as a layout leaf with a repeater of label and url, three starter links', function (): void {
    $m = null;
    foreach (ModuleBlockDefinitions::studioRegistry()->editorManifests() as $b) {
        if ($b['type'] === 'core.nav_menu') {
            $m = $b;
        }
    }
    assert_true($m !== null, 'registered');
    assert_eq('layout', $m['category']);
    assert_false((bool) $m['allows_children'], 'a leaf');
    $html = sbel_render([sbel_block('core.nav_menu', ['items' => [['label' => 'Home', 'url' => '/'], ['label' => 'About', 'url' => '/about/']]])]);
    assert_true(str_contains($html, '<nav class="sb-nav sb-nav--horizontal sb-nav--left sb-nav--plain" aria-label="Menu"><ul class="sb-nav__list"><li class="sb-nav__item"><a class="sb-nav__link" href="/">Home</a></li><li class="sb-nav__item"><a class="sb-nav__link" href="/about/">About</a></li></ul></nav>'), 'exact markup');
});

unit('nav menu: every author string is escaped, unsafe URLs are not linked, an empty menu renders nothing, other words are refused', function (): void {
    $html = sbel_render([sbel_block('core.nav_menu', ['aria_label' => 'A"><i>', 'items' => [['label' => '<b>x</b>', 'url' => '/ok/'], ['label' => 'Bad', 'url' => 'javascript:alert(1)']]])]);
    assert_false(str_contains($html, '<b>x'), 'a label is escaped');
    assert_false(str_contains($html, 'javascript:'), 'an unsafe URL never reaches the page');
    assert_false(str_contains($html, '<i>'), 'the menu name is escaped');
    assert_false(str_contains(sbel_render([sbel_block('core.nav_menu', ['items' => []])]), 'sb-nav'), 'no links, no menu');
    $block = ModuleBlockDefinitions::studioRegistry()->get('core.nav_menu');
    foreach ([['layout', 'grid'], ['align', 'justify'], ['style', 'neon']] as [$key, $bad]) {
        assert_false($block->validateProps([$key => $bad])->isValid(), "{$key} = {$bad} is refused");
    }
    assert_true($block->validateProps(['layout' => 'vertical', 'align' => 'center', 'style' => 'pill'])->isValid(), 'valid choices pass');
});

unit('nav menu: the stylesheet styles the list, both layouts, alignment and every link style', function (): void {
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    foreach (['.sb-nav__list{', '.sb-nav--vertical .sb-nav__list{', '.sb-nav--center .sb-nav__list{', '.sb-nav--right .sb-nav__list{', '.sb-nav--underline .sb-nav__link{', '.sb-nav--pill .sb-nav__link{'] as $rule) {
        assert_true(str_contains($css, $rule), $rule);
    }
    assert_true(str_contains($css, '.sb-body .sb-nav a.sb-nav__link{color:inherit}'), 'a menu link outranks the global link colour (.sb-body a:not(.sb-button)), so it keeps the surrounding text colour');
});

unit('map: renders an OpenStreetMap frame centred on the point, from the numbers alone', function (): void {
    $html = sbel_render([sbel_block('core.map', ['latitude' => 50.8503, 'longitude' => 4.3517, 'zoom' => 13, 'height' => 'lg', 'title' => 'Our office'])]);
    assert_true(str_contains($html, '<div class="sb-map sb-map--lg"><iframe src="https://www.openstreetmap.org/export/embed.html?bbox='), 'frame and height class');
    assert_true(str_contains($html, '&amp;layer=mapnik&amp;marker=50.85030,4.35170"'), 'the marker is the point');
    assert_true(str_contains($html, 'title="Our office"') && str_contains($html, 'loading="lazy"') && str_contains($html, 'referrerpolicy="no-referrer"'), 'title, lazy, no referrer');
    assert_eq(\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MapRenderer::embedUrl(50.0, 4.0, 13), \Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MapRenderer::embedUrl(50.0, 4.0, 13), 'deterministic');
    assert_false(str_contains(sbel_render([sbel_block('core.map', [])]), 'sb-map'), 'a map with no point renders nothing');
    $starter = null;
    foreach (ModuleBlockDefinitions::studioRegistry()->editorManifests() as $b) {
        if ($b['type'] === 'core.map') {
            $starter = $b;
        }
    }
    assert_true($starter !== null, 'registered');
});

unit('map: points off the map, a missing point and other words are refused, and the title is escaped', function (): void {
    $block = ModuleBlockDefinitions::studioRegistry()->get('core.map');
    foreach ([['latitude', 91], ['latitude', -86], ['longitude', 181], ['zoom', 0], ['zoom', 20], ['zoom', 3.5], ['height', 'huge']] as [$key, $bad]) {
        assert_false($block->validateProps([$key => $bad])->isValid(), "{$key} = {$bad} is refused");
    }
    assert_true($block->validateProps(['latitude' => -33.86, 'longitude' => 151.2, 'zoom' => 1, 'height' => 'sm'])->isValid(), 'valid values pass');
    $html = sbel_render([sbel_block('core.map', ['title' => '"><script>x</script>'])]);
    assert_false(str_contains($html, '<script>x'), 'the title is escaped');
    foreach (['<script', 'javascript:'] as $needle) {
        assert_false(str_contains(sbel_render([sbel_block('core.map', ['latitude' => 'x'])]), $needle), 'a stored text value is never put in the frame address');
    }
});

unit('map: the stylesheet gives the frame its height words, and the frame fills the box', function (): void {
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    foreach (\Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MapRenderer::HEIGHTS as $v) {
        assert_true(str_contains($css, ".sb-map--{$v}{height:"), "map height {$v}");
    }
    assert_true(str_contains($css, '.sb-map iframe{position:relative;z-index:1;display:block;width:100%;height:100%;border:0}'), 'the frame fills the box and sits over the pin');
});

unit('sub-folder install: root-relative links, buttons and menu items go to the install folder; nothing else is touched', function (): void {
    $base = 'https://acme.test/site';
    $blocks = [
        sbel_block('core.button', ['link' => ['href' => '/contact', 'label' => 'Contact'], 'variant' => 'primary', 'size' => 'md']),
        sbel_block('core.link', ['link' => ['href' => '/services?x=1#top', 'label' => 'Services'], 'style' => 'plain', 'align' => 'left']),
        sbel_block('core.nav_menu', ['items' => [['label' => 'Work', 'url' => '/work'], ['label' => 'Home', 'url' => '/'], ['label' => 'Done', 'url' => '/site/already'], ['label' => 'Away', 'url' => 'https://elsewhere.test/a'], ['label' => 'Top', 'url' => '#top']]]),
    ];
    $html = sbel_render($blocks, $base);
    foreach (['href="/site/contact"', 'href="/site/services?x=1#top"', 'href="/site/work"', 'href="/site/"', 'href="/site/already"', 'href="https://elsewhere.test/a"', 'href="#top"'] as $needle) {
        assert_true(str_contains($html, $needle), $needle);
    }
    assert_false(str_contains($html, 'href="/site/site/'), 'a link that already carries the folder is not prefixed twice');
    assert_false(str_contains($html, 'href="/contact"') || str_contains($html, 'href="/work"'), 'no link is left pointing at the parent site');

    $root = sbel_render($blocks, 'https://acme.test');
    assert_true(str_contains($root, 'href="/contact"') && str_contains($root, 'href="/work"'), 'at the domain root nothing changes');
});

unit('site links: rebase is idempotent, skips //host, mailto, tel and fragments, and rebases forms', function (): void {
    $r = static fn(string $h, string $b = '/site'): string => \Slate\Module\StudioBuilder\Render\SiteLinks::rebase($h, $b);
    assert_eq('<a href="/site/a">x</a>', $r('<a href="/a">x</a>'));
    assert_eq($r('<a href="/a">x</a>'), $r($r('<a href="/a">x</a>')), 'twice is the same as once');
    assert_eq('<a href="//cdn.test/a">x</a>', $r('<a href="//cdn.test/a">x</a>'), '//host is another site');
    assert_eq('<a href="mailto:a@b.test">x</a><a href="tel:+32123">y</a><a href="#s">z</a>', $r('<a href="mailto:a@b.test">x</a><a href="tel:+32123">y</a><a href="#s">z</a>'));
    assert_eq('<form action="/site/contact" method="post">', $r('<form action="/contact" method="post">'));
    assert_eq('<img src="/site/uploads/a.png" alt=""><video poster="/site/uploads/p.jpg">', $r('<img src="/uploads/a.png" alt=""><video poster="/uploads/p.jpg">'), 'an image stored as /uploads/... loads from the install, not the parent site');
    assert_eq('<img src="https://acme.test/site/uploads/a.png">', $r('<img src="https://acme.test/site/uploads/a.png">'), 'an absolute image address is left alone');
    assert_eq('<a href="/a">x</a>', $r('<a href="/a">x</a>', ''), 'no folder, no change');
    assert_eq('<a href="/a">x</a>', $r('<a href="/a">x</a>', '/'), 'a bare slash is no folder');
    assert_eq('/site', (new \Slate\Module\StudioBuilder\Render\SiteContext('https://acme.test/site/', 'A'))->basePath());
    assert_eq('', (new \Slate\Module\StudioBuilder\Render\SiteContext('https://acme.test', 'A'))->basePath());
    assert_eq('', (new \Slate\Module\StudioBuilder\Render\SiteContext('', 'A'))->basePath());
});

unit('site menus: a menu is validated as one small closed shape, and decoded safely', function (): void {
    $ok = \Slate\Module\StudioBuilder\Menu\StudioMenus::validate('header', 'Header menu', [['label' => 'Home', 'url' => '/'], ['label' => '', 'url' => ''], ['label' => 'Docs', 'url' => 'https://example.com/docs', 'new_tab' => '1']]);
    assert_eq('header', $ok['key']);
    assert_eq(2, count($ok['items']), 'an unused empty row is dropped');
    assert_true($ok['items'][1]['new_tab'] === true && $ok['items'][0]['new_tab'] === false, 'new tab is a flag');
    foreach ([
        ['Bad Key', 'N', []], ['', 'N', []], ['ok', '', []], ['ok', str_repeat('x', 81), []],
        ['ok', 'N', [['label' => 'x', 'url' => 'javascript:alert(1)']]],
        ['ok', 'N', [['label' => 'x', 'url' => '//evil.test']]],
        ['ok', 'N', [['label' => '', 'url' => '/a']]],
        ['ok', 'N', [['label' => 'x', 'url' => '']]],
        ['ok', 'N', array_fill(0, 61, ['label' => 'x', 'url' => '/a'])],
        ['ok', 'N', [['label' => 'Top', 'url' => '/a', 'children' => array_fill(0, 60, ['label' => 'x', 'url' => '/a'])]]], // submenu links count too
    ] as [$k, $n, $rows]) {
        $threw = false;
        try {
            \Slate\Module\StudioBuilder\Menu\StudioMenus::validate($k, $n, $rows);
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        assert_true($threw, 'refused: ' . json_encode([$k, $n, count($rows)]));
    }
    $decoded = \Slate\Module\StudioBuilder\Menu\StudioMenus::decode(json_encode(['menus' => [
        ['key' => 'good', 'name' => 'Good', 'items' => [['label' => 'A', 'url' => '/a']]],
        ['key' => 'evil', 'name' => 'Evil', 'items' => [['label' => 'A', 'url' => 'javascript:alert(1)']]],
        ['key' => 'BAD KEY', 'name' => 'x', 'items' => []],
    ]]));
    assert_eq(['good'], array_keys($decoded), 'a hand-edited setting cannot put an unsafe link or a bad key on a page');
    assert_eq([], \Slate\Module\StudioBuilder\Menu\StudioMenus::decode('not json'));
});

unit('site menus: a Nav menu block shows the chosen menu, falls back to its own links, and never crosses tenants', function (): void {
    $menus = \Slate\Module\StudioBuilder\Menu\StudioMenus::class;
    $menus::useForTests(SBEL_TENANT, [['key' => 'footer', 'name' => 'Footer menu', 'items' => [['label' => 'Privacy', 'url' => '/privacy'], ['label' => 'Docs', 'url' => 'https://example.com/docs', 'new_tab' => true]]]]);
    $own = [['label' => 'Own', 'url' => '/own']];

    $html = sbel_render([sbel_block('core.nav_menu', ['menu' => 'footer', 'items' => $own])]);
    assert_true(str_contains($html, '>Privacy</a>') && str_contains($html, 'target="_blank"') && str_contains($html, 'rel="noopener noreferrer"'), 'the chosen menu is shown, a new-tab link is safe');
    assert_false(str_contains($html, '>Own<'), "the block's own links are not shown when a menu is chosen");

    $gone = sbel_render([sbel_block('core.nav_menu', ['menu' => 'deleted-menu', 'items' => $own])]);
    assert_true(str_contains($gone, '>Own</a>'), 'a menu that no longer exists falls back to the block\'s own links');
    $none = sbel_render([sbel_block('core.nav_menu', ['items' => $own])]);
    assert_true(str_contains($none, '>Own</a>') && !str_contains($none, 'Privacy'), 'no menu chosen: its own links');

    $fp = $menus::fingerprint(SBEL_TENANT);
    $menus::useForTests(SBEL_TENANT, [['key' => 'footer', 'name' => 'Footer menu', 'items' => [['label' => 'Terms', 'url' => '/terms']]]]);
    assert_true($menus::fingerprint(SBEL_TENANT) !== $fp, 'editing a menu changes the fingerprint pages are compiled against');
    $menus::useForTests(SBEL_TENANT + 1000, []);
    assert_eq('-', $menus::fingerprint(SBEL_TENANT + 1000), 'a tenant with no menus has the empty fingerprint');
    assert_eq(null, $menus::find(SBEL_TENANT + 1000, 'footer'), 'another tenant does not see this tenant\'s menu');

    $block = ModuleBlockDefinitions::studioRegistry()->get('core.nav_menu');
    assert_true($block->validateProps(['menu' => 'header-2'])->isValid() && $block->validateProps(['menu' => ''])->isValid(), 'a menu key or none is valid');
    assert_false($block->validateProps(['menu' => 'Bad Key!'])->isValid(), 'anything else is refused');
    $menus::forget();
});

unit('site menus: submenus nest up to two levels; only an item with links under it may go without an address', function (): void {
    $menus = \Slate\Module\StudioBuilder\Menu\StudioMenus::class;
    $rows = [
        ['label' => 'Accueil', 'url' => '/'],
        ['label' => 'Projets', 'url' => '', 'depth' => 0],
        ['label' => 'Nos actions', 'url' => '/nos-actions/', 'depth' => 1],
        ['label' => 'SmileBox', 'url' => '/smile-box/', 'depth' => 2],
        ['label' => 'Gala', 'url' => '/gala/', 'depth' => 3],          // deeper than allowed: kept at the deepest level
        ['label' => 'Evenements', 'url' => '/evenements/', 'depth' => 1],
        ['label' => 'Soutenir', 'url' => '/soutenir/', 'depth' => 0],
    ];
    $menu = $menus::validate('header', 'Header', $rows);
    assert_eq(['Accueil', 'Projets', 'Soutenir'], array_column($menu['items'], 'label'), 'rows with a depth become a tree');
    assert_eq(['Nos actions', 'Evenements'], array_column($menu['items'][1]['children'], 'label'));
    assert_eq(['SmileBox', 'Gala'], array_column($menu['items'][1]['children'][0]['children'], 'label'), 'two levels down at most');
    assert_false(array_key_exists('children', $menu['items'][0]), 'a link with nothing under it stores no children');
    assert_eq($menu, $menus::validate('header', 'Header', $menu['items']), 'the stored tree validates to itself');
    assert_eq([0, 0, 1, 2, 2, 1, 0], array_column($menus::flatten($menu['items']), 'depth'), 'and flattens back to the form rows');
    $clamped = $menus::validate('m', 'M', [['label' => 'A', 'url' => '/a', 'depth' => 2], ['label' => 'B', 'url' => '/b', 'depth' => 2]]);
    assert_eq('B', $clamped['items'][0]['children'][0]['label'] ?? null, 'the first link is at the top, and none is more than one level below the row above');
    $flat = $menus::validate('old', 'Old', [['label' => 'A', 'url' => '/a']]);
    assert_eq(['key' => 'old', 'name' => 'Old', 'items' => [['label' => 'A', 'url' => '/a', 'new_tab' => false]]], $flat, 'a menu without submenus keeps its old shape');
    foreach ([[['label' => 'Lonely', 'url' => '']], [['label' => 'P', 'url' => ''], ['label' => 'C', 'url' => 'javascript:alert(1)', 'depth' => 1]]] as $bad) {
        $threw = false;
        try { $menus::validate('m', 'M', $bad); } catch (\InvalidArgumentException) { $threw = true; }
        assert_true($threw, 'refused: ' . json_encode($bad));
    }

    $menus::useForTests(SBEL_TENANT, [$menu]);
    $navBlock = sbel_block('core.nav_menu', ['menu' => 'header', 'layout' => 'horizontal']);
    $html = sbel_render([$navBlock]);
    assert_true((bool) preg_match('~<li class="sb-nav__item sb-nav__item--parent"><button type="button" class="sb-nav__link sb-nav__parent" aria-expanded="false" aria-controls="([^"]+)">Projets<span class="sb-nav__caret" aria-hidden="true"></span></button><ul class="sb-nav__sub sb-nav__sub--1" id="\\1">~', $html), 'an item with no address is the toggle of its submenu: ' . $html);
    assert_true((bool) preg_match('~<a class="sb-nav__link" href="/nos-actions/">Nos actions</a><button type="button" class="sb-nav__toggle" aria-expanded="false" aria-controls="([^"]+)" aria-label="[^"]*Nos actions"></button><ul class="sb-nav__sub sb-nav__sub--2" id="\\1">~', $html), 'an item with an address keeps its link and gets an arrow');
    assert_true(str_contains($html, '>SmileBox</a>') && str_contains($html, '>Gala</a>') && str_contains($html, '>Evenements</a>'), 'every link is in the page, so it works without scripts');
    preg_match_all('~ id="([^"]+)"~', $html, $ids);
    assert_eq(count($ids[1]), count(array_unique($ids[1])), 'submenu ids are unique');
    assert_eq($html, sbel_render([$navBlock]), 'and the same on every render');
    $menus::forget();

    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    assert_true(str_contains($css, '.sb-nav--horizontal .sb-nav__item--parent:is(:hover,:focus-within,[data-open]):not([data-closed])>.sb-nav__sub{opacity:1;visibility:visible'), 'a horizontal submenu drops down on hover and focus, with CSS alone');
    assert_true(str_contains($css, 'html[data-sb-ready] .sb-nav--vertical .sb-nav__item--parent:not([data-open])>.sb-nav__sub{display:none}'), 'a vertical one folds away only once the runtime can open it');
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, "['submenus', initSubmenus]") && str_contains($js, "'.sb-nav__item--parent'") && str_contains($js, "setAttribute('aria-expanded'"), 'the runtime toggles submenus');
    $parts = \Slate\Module\StudioBuilder\Registry\BlockParts::for('core.nav_menu');
    assert_true(isset($parts['submenu'], $parts['sublink'], $parts['toggle']), 'the submenu, its links and the arrow can be styled');
});

unit('heading highlight: the highlighted part has its own colour, italic, weight and underline, and nothing unsafe reaches the page', function (): void {
    $html = sbel_render([sbel_block('core.heading', ['text' => 'Three steps, in order.', 'highlight' => 'in order.', 'highlight_color' => '#d4623d', 'highlight_italic' => true, 'highlight_weight' => 'bold', 'highlight_underline' => true])]);
    assert_true(str_contains($html, 'Three steps, <span class="sb-hl sb-hl--italic sb-hl--underline sb-hl--w-bold" style="color:#d4623d;font-style:italic;font-weight:700;text-decoration:underline;text-underline-offset:.15em">in order.</span>'), 'the phrase carries its own look, also inline so a site\'s own highlight CSS cannot override it');

    $token = sbel_render([sbel_block('core.heading', ['text' => 'Hello world', 'highlight' => 'world', 'highlight_color_token' => 'text.accent'])]);
    $var = 'var(' . \Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme::cssVarName('text.accent') . ')';
    assert_true(str_contains($token, '<span class="sb-hl sb-fg--text-accent" style="color:' . $var . '">world</span>'), 'a theme colour is its token class and, inline, its variable');
    $upright = sbel_render([sbel_block('core.heading', ['text' => 'Hello world', 'highlight' => 'world', 'highlight_color_token' => 'text.accent', 'highlight_weight' => 'inherit'])]);
    assert_false(str_contains($upright, 'font-style') || str_contains($upright, 'font-weight'), 'italic off and weight Inherit write nothing: the site default look stays');

    $both = sbel_render([sbel_block('core.heading', ['text' => 'Hello world', 'highlight' => 'world', 'highlight_color_token' => 'text.accent', 'highlight_color' => '#112233'])]);
    assert_true(str_contains($both, 'style="color:#112233"') && !str_contains($both, 'sb-fg--text-accent">world'), 'a custom colour wins over the theme one');

    $plain = sbel_render([sbel_block('core.heading', ['text' => 'Hello world', 'highlight' => 'world'])]);
    assert_true(str_contains($plain, '<span class="sb-hl">world</span>'), 'no look set: the default accent highlight, as before');

    foreach (['red;position:fixed', 'url(javascript:x)', '#12', '"><script>', 'expression(1)'] as $bad) {
        $out = sbel_render([sbel_block('core.heading', ['text' => 'Hello world', 'highlight' => 'world', 'highlight_color' => $bad])]);
        assert_false(str_contains($out, 'style='), "an unsafe colour is never written: {$bad}");
        $block = ModuleBlockDefinitions::studioRegistry()->get('core.heading');
        assert_false($block->validateProps(['text' => 'x', 'highlight_color' => $bad])->isValid(), "and the editor refuses it: {$bad}");
    }
    $block = ModuleBlockDefinitions::studioRegistry()->get('core.heading');
    assert_true($block->validateProps(['text' => 'x', 'highlight_color' => '#d4623d', 'highlight_weight' => 'medium', 'highlight_italic' => true])->isValid(), 'a valid look passes');
    assert_false($block->validateProps(['text' => 'x', 'highlight_weight' => 'ultra'])->isValid(), 'only the listed weights');

    $hero = sbel_render([sbel_block('core.hero', ['heading' => 'Build in order', 'highlight' => 'in order', 'highlight_italic' => true, 'highlight_color' => '#d4623d'])]);
    assert_true(str_contains($hero, '<span class="sb-hl sb-hl--italic" style="color:#d4623d;font-style:italic">in order</span>'), 'the hero heading takes the same look');
});

unit('elements: layout.offcanvas opens from an icon trigger (a hamburger) with or without its text', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    assert_true($registry->get('layout.offcanvas')->validateProps(['trigger_icon' => 'menu-short', 'trigger_variant' => 'plain', 'trigger_show_text' => false, 'trigger_icon_size' => 28])->isValid(), 'icon trigger props validate');
    assert_false($registry->get('layout.offcanvas')->validateProps(['trigger_icon' => 'burger'])->isValid(), 'only library icons');
    assert_false($registry->get('layout.offcanvas')->validateProps(['trigger_icon_size' => 200])->isValid(), 'icon size is bounded');

    $iconOnly = sbel_render([sbel_block('layout.offcanvas', ['drawer_id' => 'site-menu', 'title' => '', 'trigger_text' => 'Menu "main"', 'trigger_icon' => 'menu-short', 'trigger_variant' => 'plain', 'trigger_show_text' => false, 'trigger_icon_size' => 28])]);
    assert_true(str_contains($iconOnly, 'class="sb-offcanvas-trigger sb-button sb-button--plain sb-offcanvas-trigger--icon"'), 'plain icon trigger classes');
    assert_true(str_contains($iconOnly, 'aria-label="Menu &quot;main&quot;"'), 'the hidden text names the link, escaped');
    assert_true(str_contains($iconOnly, 'width="28" height="28"') && str_contains($iconOnly, IconLibrary::markup('menu-short')), 'the icon at its size');
    assert_false(str_contains($iconOnly, 'sb-offcanvas-trigger__label'), 'no visible text');
    assert_true(str_contains($iconOnly, 'sb-offcanvas__header--bare') && !str_contains($iconOnly, '<h3 id="site-menu-title"'), 'no title: a bare header');
    assert_true(str_contains($iconOnly, 'role="dialog" aria-label="Menu"'), 'the untitled drawer is still named');

    $noText = sbel_render([sbel_block('layout.offcanvas', ['trigger_text' => '', 'trigger_icon' => 'menu'])]);
    assert_true(str_contains($noText, 'aria-label="Open menu"'), 'an icon with no text gets a default name');

    $both = sbel_render([sbel_block('layout.offcanvas', ['trigger_text' => 'Menu', 'trigger_icon' => 'menu'])]);
    assert_true(str_contains($both, '<span class="sb-offcanvas-trigger__label">Menu</span>') && !str_contains($both, 'aria-label="Menu"><svg'), 'icon and visible text, no duplicate name');

    $text = sbel_render([sbel_block('layout.offcanvas', ['trigger_text' => 'Open Menu'])]);
    assert_true(str_contains($text, '>Open Menu</span></a>') && !str_contains($text, '<svg class="sb-offcanvas-trigger__icon'), 'a text trigger as before, no icon');
    assert_true(str_contains($text, 'aria-labelledby='), 'a titled drawer is labelled by its title');
});
