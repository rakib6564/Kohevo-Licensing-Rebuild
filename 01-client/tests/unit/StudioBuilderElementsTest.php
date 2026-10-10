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
function sbel_render(array $blocks): string
{
    $tenants   = new TenantContext();
    $registry  = ModuleBlockDefinitions::studioRegistry();
    $renderers = BlockRendererRegistry::withStudioRenderers();
    $media     = new _SbelNoMedia();
    $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $compiler  = new StudioCompiler($tenants, $registry, $renderers, $documents, new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500']), new ChromeResolver(), $media);
    $site      = new SiteContext('https://acme.test', 'Acme', 'https://acme.test/l.png', 'https://acme.test/f.png', 'en');
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
