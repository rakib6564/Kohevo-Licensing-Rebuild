<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 4 Server Renderer.
 *
 * Dependency-free (no database): drives the real render pipeline — preparer,
 * DocumentRenderer, StudioCompiler (without a compilation store), dynamic slot
 * fill, page assembly — against fake media / fake providers / injected
 * entitlement, via the autoloader only.
 *
 * Verifies: known / unknown / invalid-props / unsupported-version blocks,
 * token resolution and CSS-value sanitization, static determinism, dynamic
 * deferral + fill, entitlement denial, public vs authoring provider rules,
 * bounded provider output, renderer failure paths, output-side rich-text
 * sanitization (incl. forged dynamic markers), media resolution, SEO
 * (canonical containment, preview noindex), cache policy / cache key, render
 * context boundaries, reserved-route handling, auth-state visibility, and the
 * platform-signature slot.
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
    $studioP4UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Exception\StudioAuthenticationException;
use Slate\Module\StudioBuilder\Exception\StudioRenderException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Provider\DataProviderInterface;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\PublicDataProviderInterface;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Cache\StudioCacheKey;
use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\DynamicSlotResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\PageDocumentAssembler;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\RichTextSanitizer;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

const SBP4U_TENANT = 101;

/** Fake media library: only the ids it was given exist. */
final class _Sbp4uFakeMedia implements MediaResolverInterface
{
    /** @param array<int, ResolvedMedia> $items */
    public function __construct(private readonly array $items = []) {}
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return $this->items[$mediaId] ?? null;
    }
}

/** Public-marked fake for `booking.services`: returns more rows than its bound. */
final class _Sbp4uPublicServices implements PublicDataProviderInterface
{
    public int $calls = 0;
    public function key(): string { return 'booking.services'; }
    public function parameterSchema(): FieldSchema { return FieldSchema::define([]); }
    public function requiredEntitlement(): ?string { return 'booking'; }
    public function requiredPermission(): string { return StudioPermissions::VIEW; }
    public function maxResults(): int { return 2; }
    public function execute(TenantContext $tenants, array $params): array
    {
        $this->calls++;
        return [
            ['id' => 1, 'name' => 'Cut <b>&</b> Style', 'description' => '', 'duration_minutes' => 45, 'price_cents' => 5000, 'currency' => 'usd', 'nested' => ['x' => 1]],
            ['id' => 2, 'name' => 'Colour', 'description' => 'Full colour', 'duration_minutes' => 90, 'price_cents' => 12000, 'currency' => 'USD'],
            ['id' => 3, 'name' => 'Should be cut off by maxResults', 'description' => '', 'duration_minutes' => 10, 'price_cents' => 100, 'currency' => 'USD'],
        ];
    }
}

/** NOT public-marked fake for `membership.plans`: may run for authors, never for the public. */
final class _Sbp4uPrivatePlans implements DataProviderInterface
{
    public function key(): string { return 'membership.plans'; }
    public function parameterSchema(): FieldSchema { return FieldSchema::define([]); }
    public function requiredEntitlement(): ?string { return 'membership'; }
    public function requiredPermission(): string { return StudioPermissions::VIEW; }
    public function maxResults(): int { return 10; }
    public function execute(TenantContext $tenants, array $params): array
    {
        return [['id' => 7, 'name' => 'Private Plan', 'description' => '', 'price_cents' => 1, 'currency' => 'USD']];
    }
}

/** A static block renderer that always throws (renderer-failure paths). */
final class _Sbp4uBoomRenderer implements BlockRendererInterface
{
    public function __construct(private readonly bool $dynamic = false) {}
    public function type(): string { return $this->dynamic ? 'test.boom_dynamic' : 'test.boom'; }
    public function isDynamic(): bool { return $this->dynamic; }
    public function render(BlockRenderScope $scope): string { throw new \RuntimeException('renderer exploded: SECRET-INTERNAL'); }
}

/**
 * @return array{registry: BlockRegistry, renderers: BlockRendererRegistry, documents: DocumentRenderer, compiler: StudioCompiler,
 *   slots: DynamicSlotResolver, assembler: PageDocumentAssembler, tenants: TenantContext, services: _Sbp4uPublicServices, media: _Sbp4uFakeMedia}
 */
function sbp4u_pipeline(array $opts = []): array
{
    $tenants  = new TenantContext();
    $registry = ModuleBlockDefinitions::studioRegistry();
    $registry->register(new DeclarativeBlockDefinition(type: 'test.boom', version: 1, label: 'Boom', category: 'test', icon: 'x', schema: FieldSchema::define([])));
    $registry->register(new DeclarativeBlockDefinition(type: 'test.boom_dynamic', version: 1, label: 'Boom D', category: 'test', icon: 'x', schema: FieldSchema::define([])));
    $renderers = BlockRendererRegistry::withStudioRenderers();
    $renderers->register(new _Sbp4uBoomRenderer(false));
    $renderers->register(new _Sbp4uBoomRenderer(true));

    $services  = new _Sbp4uPublicServices();
    $providers = new DataProviderRegistry();
    $providers->register($services);
    $providers->register(new _Sbp4uPrivatePlans());

    $media = $opts['media'] ?? new _Sbp4uFakeMedia([
        42 => new ResolvedMedia(42, 'https://acme.test/uploads/media/2026/01/hero.jpg', 1200, 800),
    ]);
    $branding = $opts['branding'] ?? static fn(): array => ['accent' => '#ff5500', 'ink' => 'red;}</style><script>alert(1)</script>', 'heading' => 'Inter'];

    $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver($providers, $tenants));
    $compiler  = new StudioCompiler($tenants, $registry, $renderers, $documents, new ThemeResolver(null, $branding), new ChromeResolver(), $media);
    $assembler = new PageDocumentAssembler($opts['signature'] ?? static fn(): string => 'Powered by Kohevo');

    return [
        'registry' => $registry, 'renderers' => $renderers, 'documents' => $documents, 'compiler' => $compiler,
        'slots' => new DynamicSlotResolver($documents), 'assembler' => $assembler, 'tenants' => $tenants,
        'services' => $services, 'media' => $media,
    ];
}

/** @return array<string, mixed> */
function sbp4u_block(string $type, array $props = [], array $extra = []): array
{
    return array_merge([
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => $type,
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $extra);
}

/** @return array<string, mixed> */
function sbp4u_doc(array $blocks, array $settings = [], array $seo = []): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'About us');
    $doc['settings'] = array_merge($doc['settings'], $settings);
    $doc['seo'] = array_merge($doc['seo'], $seo);
    $doc['sections'] = [[
        'blocks'     => $blocks,
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Main',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

function sbp4u_page(string $routeMode = 'standalone'): PageAddress
{
    return new PageAddress(5, '11111111-2222-4333-8444-555555555555', 'About', 'about', 'page', 'published', $routeMode, 9, 9);
}

/** @return array<string, mixed> */
function sbp4u_revision(array $doc): array
{
    return ['id' => 9, 'page_id' => 5, 'document_json' => CanonicalJson::encode($doc)];
}

function sbp4u_site(): SiteContext
{
    return new SiteContext('https://acme.test', 'Acme Studio', 'https://acme.test/uploads/branding/logo.png', 'https://acme.test/uploads/branding/fav.png', 'en');
}

function sbp4u_public(array $entitled = ['booking', 'membership', 'forms']): RenderContext
{
    return RenderContext::forPublic(SBP4U_TENANT, sbp4u_site(), static fn(string $m): bool => in_array($m, $entitled, true));
}

function sbp4u_preview(array $perms = [StudioPermissions::VIEW], array $entitled = ['booking', 'membership', 'forms']): RenderContext
{
    return RenderContext::forPreview(SBP4U_TENANT, sbp4u_site(), StudioActor::authenticated(3, $perms), static fn(string $m): bool => in_array($m, $entitled, true));
}

/** Full pipeline (compile -> fill -> assemble) for one document, as tenant 101. */
function sbp4u_render(array $p, array $doc, RenderContext $ctx, string $routeMode = 'standalone'): array
{
    return $p['tenants']->runAs(SBP4U_TENANT, static function () use ($p, $doc, $ctx, $routeMode): array {
        $compiled = $p['compiler']->compile(sbp4u_page($routeMode), sbp4u_revision($doc), $ctx);
        $filled   = $p['slots']->fill($compiled, $ctx);
        return ['compiled' => $compiled, 'html' => $p['assembler']->assemble($compiled, $filled, $ctx)];
    });
}

// ── Renderer: known blocks, escaping, determinism ───────────────────────────

unit('phase4 renderer: known core blocks render escaped, deterministic static output', function (): void {
    $p = sbp4u_pipeline();
    $doc = sbp4u_doc([
        sbp4u_block('core.heading', ['text' => 'Tom & Jerry <b>bold</b>', 'level' => 'h3']),
        sbp4u_block('core.hero', ['heading' => 'Welcome "home"', 'eyebrow' => 'New', 'subheading' => "line1\nline2", 'primary_cta' => ['label' => 'Go', 'href' => 'https://example.org/x', 'target' => '_blank'], 'media' => ['media_id' => 42, 'alt' => 'Hero alt'], 'accent_token' => 'color.accent']),
        sbp4u_block('core.button', ['link' => ['label' => 'Contact', 'href' => '/contact'], 'variant' => 'outline']),
        sbp4u_block('core.container', ['direction' => 'horizontal'], ['children' => [sbp4u_block('core.heading', ['text' => 'Nested'])]]),
        sbp4u_block('core.feature_list', ['title' => 'Why', 'columns' => 2, 'items' => [['heading' => 'Fast', 'body' => 'Very', 'url' => '/fast']]]),
    ]);

    $a = sbp4u_render($p, $doc, sbp4u_public());
    $b = sbp4u_render($p, $doc, sbp4u_public());
    $html = $a['html'];

    assert_true(str_contains($html, '<h3 class="sb-heading">Tom &amp; Jerry &lt;b&gt;bold&lt;/b&gt;</h3>'), 'heading text must be escaped, level honoured');
    assert_true(str_contains($html, 'Welcome &quot;home&quot;'), 'hero heading escaped');
    assert_true(str_contains($html, 'line1<br>'), 'multi-line text keeps line breaks, escaped');
    assert_true(str_contains($html, 'href="https://example.org/x" target="_blank" rel="noopener noreferrer"'), '_blank links always get noopener noreferrer');
    assert_true(str_contains($html, 'src="https://acme.test/uploads/media/2026/01/hero.jpg"'), 'media resolved through the media resolver');
    assert_true(str_contains($html, 'sb-fg--color-accent'), 'token_ref prop becomes a symbolic utility class');
    assert_true(str_contains($html, 'sb-button sb-button--outline'), 'button variant class');
    assert_true(str_contains($html, 'sb-stack--horizontal') && str_contains($html, 'Nested'), 'container renders its children');
    assert_true(str_contains($html, 'sb-md-cols-2') && str_contains($html, 'href="/fast"'), 'feature list columns + item link');
    assert_true(!str_contains($html, 'data-sb-node'), 'public output carries no editor node metadata');
    assert_eq($a['compiled']->html, $b['compiled']->html, 'static compilation must be deterministic for identical input');
    assert_eq($a['compiled']->contentHash, $b['compiled']->contentHash);
    assert_eq([], $a['compiled']->dynamicManifest['nodes'], 'a static-only page defers nothing');
});

unit('phase4 renderer: unknown block type, invalid props and unsupported version fail safe per block', function (): void {
    $p = sbp4u_pipeline();
    $good = sbp4u_block('core.heading', ['text' => 'Still here']);
    $unknown = sbp4u_block('acme.widget', ['script' => '<script>alert(1)</script>']);
    $badProps = sbp4u_block('core.heading', ['text' => 'x', 'level' => 'h9']);
    $badVersion = sbp4u_block('core.heading', ['text' => 'v99 text'], ['version' => 99]);
    $doc = sbp4u_doc([$good, $unknown, $badProps, $badVersion]);

    $public = sbp4u_render($p, $doc, sbp4u_public());
    assert_true(str_contains($public['html'], 'Still here'), 'a valid block keeps rendering beside broken ones');
    assert_true(!str_contains($public['html'], 'alert(1)') && !str_contains($public['html'], 'acme.widget'), 'unknown block content is never emitted');
    assert_true(!str_contains($public['html'], 'v99 text'), 'unsupported block version is not rendered');
    assert_true(!str_contains($public['html'], 'class="sb-unavailable"'), 'public output shows no diagnostic hints');
    assert_true($public['compiled']->headAssets['degraded'] === true, 'the fail-soft path is recorded on the artifact');

    $preview = sbp4u_render($p, $doc, sbp4u_preview());
    assert_eq(3, substr_count($preview['html'], 'class="sb-unavailable"'), 'authors see one labelled fallback per broken block');
    assert_true(str_contains($preview['html'], '(unknown_block_type)') && str_contains($preview['html'], '(unsupported_block_version)') && str_contains($preview['html'], '(invalid_block)'));
    assert_true(!str_contains($preview['html'], 'alert(1)'), 'even authoring previews never execute unknown content');
});

unit('phase4 renderer: an invalid document envelope is a hard, detail-free render failure', function (): void {
    $p = sbp4u_pipeline();
    $doc = sbp4u_doc([sbp4u_block('core.heading', ['text' => 'x'])]);
    $doc['settings']['header_mode'] = 'bogus';
    assert_throws(StudioRenderException::class, function () use ($p, $doc): void {
        sbp4u_render($p, $doc, sbp4u_public());
    });
    assert_throws(StudioRenderException::class, function () use ($p): void {
        $p['tenants']->runAs(SBP4U_TENANT, static fn() => $p['compiler']->compile(sbp4u_page(), ['id' => 9, 'page_id' => 5, 'document_json' => '{not json'], sbp4u_public()));
    }, 'undecodable document');
    assert_throws(StudioRenderException::class, function () use ($p, $doc): void {
        $p['tenants']->runAs(SBP4U_TENANT, static fn() => $p['compiler']->compile(sbp4u_page(), ['id' => 9, 'page_id' => 777, 'document_json' => CanonicalJson::encode(sbp4u_doc([]))], sbp4u_public()));
    }, 'a revision of another page is refused');
});

unit('phase4 renderer: static renderer failure aborts compilation with a detail-free exception', function (): void {
    $p = sbp4u_pipeline();
    $doc = sbp4u_doc([sbp4u_block('core.heading', ['text' => 'ok']), sbp4u_block('test.boom')]);
    $caught = null;
    try {
        sbp4u_render($p, $doc, sbp4u_public());
    } catch (StudioRenderException $e) {
        $caught = $e;
    }
    assert_true($caught !== null, 'a throwing static renderer must fail the compilation (no partial artifact)');
    assert_true(!str_contains($caught->getMessage(), 'SECRET-INTERNAL'), 'the renderer\'s internal message is not surfaced in the exception message');
});

unit('phase4 renderer: dynamic renderer failure degrades only that node at request time', function (): void {
    $p = sbp4u_pipeline();
    $doc = sbp4u_doc([sbp4u_block('core.heading', ['text' => 'Page survives']), sbp4u_block('test.boom_dynamic')]);
    $r = sbp4u_render($p, $doc, sbp4u_public());
    assert_true(str_contains($r['html'], 'Page survives'));
    assert_true(!str_contains($r['html'], 'SECRET-INTERNAL') && !str_contains($r['html'], '<!--sb-dyn'), 'no error text and no unresolved marker reaches output');
});

// ── Theme / tokens ───────────────────────────────────────────────────────────

unit('phase4 theme: defaults < branding < stored tokens, unsafe values dropped', function (): void {
    $theme = (new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500', 'ink' => 'red;}</style><script>', 'body' => 'Inter, sans-serif']))->resolve('default');
    assert_eq('#ff5500', $theme->value('color.accent'), 'tenant branding accent feeds color.accent');
    assert_eq('#ff5500', $theme->value('surface.accent'));
    assert_eq(ThemeResolver::DEFAULT_TOKENS['text.primary'], $theme->value('text.primary'), 'an unsafe branding value is ignored, default kept');
    assert_eq('Inter, sans-serif', $theme->value('font.body'));
    $css = $theme->rootCss();
    assert_true(str_contains($css, '--sb-color-accent:#ff5500'));
    assert_true(!str_contains($css, '<') && !str_contains($css, '}</') && substr_count($css, '}') === 1, 'root CSS can never close the style element or rule early');

    foreach ([
        ['surface.primary', 'url(javascript:alert(1))'], ['surface.primary', 'expression(alert(1))'], ['font.body', 'Arial;}body{x:y'],
        ['space.md', '1px; background:red'], ['shadow.md', '0 0 1px red;'], ['text.primary', '#12345z'], ['nope.key', '#fff'],
    ] as [$ref, $value]) {
        assert_null(ThemeResolver::sanitizeValue($ref, $value), "unsafe token value must be rejected: {$ref} = {$value}");
    }
    assert_eq('rgba(0,0,0,0.5)', ThemeResolver::sanitizeValue('surface.overlay', 'rgba(0,0,0,0.5)'));
    assert_eq('0 4px 12px rgba(0,0,0,0.08)', ThemeResolver::sanitizeValue('shadow.lg', '0 4px 12px rgba(0,0,0,0.08)'));
    assert_eq('1rem 2rem', ThemeResolver::sanitizeValue('space.xl', '1rem 2rem'));
});

unit('phase4 theme: block style tokens become utility classes only when resolvable', function (): void {
    $p = sbp4u_pipeline();
    $style = CanonicalDocumentSchema::defaultBlockStyle();
    $style['surface_token'] = 'surface.muted';
    $style['text_token'] = 'text.does_not_exist';
    $style['align'] = ['base' => 'center', 'md' => 'right'];
    $r = sbp4u_render($p, sbp4u_doc([sbp4u_block('core.heading', ['text' => 't'], ['style' => $style])]), sbp4u_public());
    assert_true(str_contains($r['html'], 'sb-bg--surface-muted') && str_contains($r['compiled']->css, '.sb-bg--surface-muted{background-color:var(--sb-surface-muted)}'));
    assert_true(!str_contains($r['html'], 'sb-fg--text-does_not_exist'), 'an unresolvable token contributes no class');
    assert_true(str_contains($r['html'], 'sb-align-center') && str_contains($r['html'], 'sb-md-align-right'));
});

// ── Dynamic data ─────────────────────────────────────────────────────────────

unit('phase4 dynamic: provider-bound blocks are deferred, never baked, and filled per request (bounded, normalized)', function (): void {
    $p = sbp4u_pipeline();
    $booking = sbp4u_block('booking.services', ['heading' => 'Our services'], ['bindings' => ['items' => ['provider' => 'booking.services']]]);
    $r = sbp4u_render($p, sbp4u_doc([sbp4u_block('core.heading', ['text' => 'Static']), $booking]), sbp4u_public());

    assert_eq(1, count($r['compiled']->dynamicManifest['nodes']), 'the booking block is deferred');
    assert_true(str_contains($r['compiled']->html, '<!--sb-dyn:' . $r['compiled']->dynamicManifest['nonce'] . ':0-->'), 'compiled HTML holds only a nonce marker');
    assert_true(!str_contains($r['compiled']->html, 'Colour'), 'provider data is never baked into the compiled artifact');

    $html = $r['html'];
    assert_true(str_contains($html, 'Cut &lt;b&gt;&amp;&lt;/b&gt; Style'), 'provider strings are escaped');
    assert_true(str_contains($html, '120.00 USD') && str_contains($html, '45 min'));
    assert_true(!str_contains($html, 'Should be cut off'), 'rows are bounded by the provider maxResults()');
    assert_true(str_contains($html, 'href="https://acme.test/book"'), 'links to the Booking module\'s own public route');
    assert_true(!str_contains($html, '<!--sb-dyn'), 'every marker is resolved');
    $calls = $p['services']->calls;
    sbp4u_render($p, sbp4u_doc([$booking]), sbp4u_public());
    assert_true($p['services']->calls > $calls, 'dynamic data is fetched on every request, not cached in the artifact');

    assert_eq([['id' => 1, 'name' => 'x']], ProviderBindingResolver::normalizeRows([['id' => 1, 'name' => 'x', 'deep' => ['a' => 1], 'Bad-Key' => 'y']]), 'nested values and unsafe keys are dropped');
});

unit('phase4 dynamic: missing entitlement denies execution; public cannot run non-public providers', function (): void {
    $p = sbp4u_pipeline();
    $booking = sbp4u_block('booking.services', ['heading' => 'Svc'], ['bindings' => ['items' => ['provider' => 'booking.services']]]);
    $plans = sbp4u_block('membership.plans', ['heading' => 'Plans'], ['bindings' => ['items' => ['provider' => 'membership.plans']]]);

    $before = $p['services']->calls;
    $unentitled = sbp4u_render($p, sbp4u_doc([$booking]), sbp4u_public([]));
    assert_eq($before, $p['services']->calls, 'an unentitled module\'s provider is never executed');
    assert_true(!str_contains($unentitled['html'], 'Svc') && !str_contains($unentitled['html'], 'sb-block--booking-services'), 'public output drops the block entirely (no licence hint)');

    $publicPlans = sbp4u_render($p, sbp4u_doc([$plans]), sbp4u_public());
    assert_true(!str_contains($publicPlans['html'], 'Private Plan'), 'a provider not marked public never runs for anonymous visitors');

    $previewPlans = sbp4u_render($p, sbp4u_doc([$plans]), sbp4u_preview([StudioPermissions::VIEW]));
    assert_true(str_contains($previewPlans['html'], 'Private Plan'), 'an authorized author may preview it');

    $previewNoPerm = sbp4u_render($p, sbp4u_doc([$plans]), sbp4u_preview([StudioPermissions::EDIT]));
    assert_true(!str_contains($previewNoPerm['html'], 'Private Plan') && str_contains($previewNoPerm['html'], 'sb-unavailable'), 'author without the provider permission gets the controlled empty state');
});

// ── Rich text / XSS ──────────────────────────────────────────────────────────

unit('phase4 security: output-side rich text sanitizer', function (): void {
    $out = RichTextSanitizer::sanitize('<p onclick="x()">Hi <a href="javascript:alert(1)">bad</a> <a href="/ok" target="_blank">ok</a><!--sb-dyn:abcdefabcdefabcdef:0--><script>alert(1)</script><strong>open');
    assert_true(!str_contains($out, 'onclick') && !str_contains($out, 'javascript:') && !str_contains($out, '<script'), 'handlers, bad URLs, scripts removed');
    assert_true(!str_contains($out, '<!--'), 'comments (incl. forged dynamic markers) are dropped');
    assert_true(str_contains($out, '<a href="/ok" target="_blank" rel="noopener noreferrer">ok</a>'));
    assert_true(str_contains($out, '<a>bad</a>'), 'an unsafe link keeps its text but loses its href');
    assert_true(str_ends_with($out, '<strong>open</strong></p>') && substr_count($out, '<p>') === substr_count($out, '</p>'), 'unclosed elements are closed, innermost first');
    assert_eq('a &lt; b', RichTextSanitizer::sanitize('a < b'));
});

unit('phase4 security: authored content can never forge a dynamic marker', function (): void {
    $p = sbp4u_pipeline();
    $booking = sbp4u_block('booking.services', [], ['bindings' => ['items' => ['provider' => 'booking.services']]]);
    $r = sbp4u_render($p, sbp4u_doc([sbp4u_block('core.heading', ['text' => '<!--sb-dyn:x:0-->']), $booking]), sbp4u_public());
    assert_true(str_contains($r['html'], '&lt;!--sb-dyn:x:0--&gt;'), 'a marker-shaped string in authored text is escaped, not interpreted');
});

// ── Media ────────────────────────────────────────────────────────────────────

unit('phase4 media: valid, missing and foreign media references', function (): void {
    $p = sbp4u_pipeline();
    $img = static fn(int $id): array => sbp4u_block('core.image', ['media' => ['media_id' => $id, 'alt' => 'A "q"'], 'caption' => 'Cap']);
    $r = sbp4u_render($p, sbp4u_doc([$img(42), $img(999)]), sbp4u_public());
    assert_true(str_contains($r['html'], 'src="https://acme.test/uploads/media/2026/01/hero.jpg" alt="A &quot;q&quot;" width="1200" height="800"'));
    $main = substr($r['html'], strpos($r['html'], '<main'));
    assert_eq(1, substr_count($main, '<img'), 'an unresolvable (missing / other-tenant) media id renders no image');
    $preview = sbp4u_render($p, sbp4u_doc([$img(999)]), sbp4u_preview());
    assert_true(str_contains($preview['html'], 'Image unavailable'), 'authors see the missing image');
});

// ── SEO ──────────────────────────────────────────────────────────────────────

unit('phase4 seo: title/description/robots, canonical containment, og:image, preview noindex', function (): void {
    $site = sbp4u_site();
    $media = new _Sbp4uFakeMedia([42 => new ResolvedMedia(42, '/uploads/media/og.png')]);
    $page = sbp4u_page();

    $h = SeoHead::build(['title' => 'T', 'description' => 'D', 'robots' => 'noindex,follow', 'canonical_url' => 'https://evil.test/steal', 'og_image_media_id' => 42], $page, $site, RenderMode::Public, $media);
    assert_eq('https://acme.test/about', $h->canonicalUrl, 'a foreign-host canonical is replaced by the page\'s own URL');
    assert_eq('noindex,follow', $h->robots);
    assert_eq('https://acme.test/uploads/media/og.png', $h->ogImageUrl);
    assert_eq('https://acme.test/other', SeoHead::canonical('/other', $site, '/about'));
    assert_eq('https://ACME.test/x', SeoHead::canonical('https://ACME.test/x', $site, '/about'), 'same host (case-insensitive) is honoured as authored');
    assert_eq('https://acme.test/about', SeoHead::canonical('mailto:a@b.co', $site, '/about'));
    assert_eq('https://acme.test/', SeoHead::build([], sbp4u_page('homepage'), $site, RenderMode::Public, $media)->canonicalUrl, 'homepage canonical is the site root');
    assert_eq('About', SeoHead::build(['title' => ''], $page, $site, RenderMode::Public, $media)->title, 'title falls back to the page title');

    $pv = SeoHead::build(['robots' => 'index,follow', 'canonical_url' => '/x'], $page, $site, RenderMode::Preview, $media);
    assert_eq('noindex,nofollow', $pv->robots, 'preview is never indexable');
    assert_null($pv->canonicalUrl, 'preview declares no canonical');
    assert_true(str_contains($pv->tags(), '<meta name="robots" content="noindex,nofollow">'));
});

// ── Preview / public context boundaries ─────────────────────────────────────

unit('phase4 context: preview needs an authenticated actor, public needs a tenant; editor metadata only in editor', function (): void {
    assert_throws(StudioAuthenticationException::class, static fn() => RenderContext::forPreview(SBP4U_TENANT, sbp4u_site(), StudioActor::guest()));
    assert_throws(StudioTenantScopeException::class, static fn() => RenderContext::forPublic(0, sbp4u_site()));
    assert_throws(StudioTenantScopeException::class, static fn() => RenderContext::forPublic(-5, sbp4u_site()));

    $p = sbp4u_pipeline();
    $doc = sbp4u_doc([sbp4u_block('core.heading', ['text' => 'x'])], [], ['robots' => 'index,follow']);
    $preview = sbp4u_render($p, $doc, sbp4u_preview());
    assert_true(str_contains($preview['html'], 'content="noindex,nofollow"') && str_contains($preview['html'], 'sb-preview-banner'));
    assert_true(!str_contains($preview['html'], 'rel="canonical"'));

    $editor = RenderContext::forEditor(SBP4U_TENANT, sbp4u_site(), StudioActor::authenticated(3, [StudioPermissions::EDIT]), static fn(): bool => true);
    $ed = sbp4u_render($p, $doc, $editor);
    assert_true(str_contains($ed['html'], 'data-sb-node="blk_') && str_contains($ed['html'], 'data-sb-type="core.heading"'), 'editor canvas gets node metadata');
    assert_true(str_contains($ed['html'], 'content="noindex,nofollow"'));

    $pub = sbp4u_render($p, $doc, sbp4u_public());
    assert_true(str_contains($pub['html'], 'content="index,follow"') && str_contains($pub['html'], '<link rel="canonical" href="https://acme.test/about">'));

    $wrongTenant = RenderContext::forPublic(202, sbp4u_site(), static fn(): bool => true);
    assert_throws(StudioTenantScopeException::class, function () use ($p, $doc, $wrongTenant): void {
        $p['tenants']->runAs(SBP4U_TENANT, static fn() => $p['compiler']->compile(sbp4u_page(), sbp4u_revision($doc), $wrongTenant));
    }, 'a context for another tenant than the active TenantContext is refused');
});

unit('phase4 visibility: authenticated-only content never enters public output', function (): void {
    $p = sbp4u_pipeline();
    $members = sbp4u_block('core.heading', ['text' => 'Members only'], ['visibility' => ['auth_state' => 'authenticated', 'devices' => ['base', 'sm', 'md', 'lg']]]);
    $mobileHidden = sbp4u_block('core.heading', ['text' => 'Desktop'], ['visibility' => ['auth_state' => 'any', 'devices' => ['md', 'lg']]]);
    $doc = sbp4u_doc([$members, $mobileHidden]);
    $pub = sbp4u_render($p, $doc, sbp4u_public());
    assert_true(!str_contains($pub['html'], 'Members only'), 'public (anonymous) output omits authenticated-only blocks');
    assert_true(str_contains($pub['html'], 'sb-hide-base') && str_contains($pub['html'], 'sb-hide-sm'), 'device visibility becomes breakpoint classes');
    assert_true(str_contains(sbp4u_render($p, $doc, sbp4u_preview())['html'], 'Members only'), 'authors preview every audience');
});

// ── Platform identity ───────────────────────────────────────────────────────

unit('phase4 identity: platform signature sits outside tenant chrome and survives hidden footer / hostile branding', function (): void {
    $p = sbp4u_pipeline(['branding' => static fn(): array => ['accent' => '#000000', 'heading' => 'Kohevo'], 'signature' => static fn(): string => 'Powered by Kohevo']);
    $doc = sbp4u_doc([sbp4u_block('core.heading', ['text' => 'x'])], ['footer_mode' => 'hidden', 'header_mode' => 'hidden']);
    $html = sbp4u_render($p, $doc, sbp4u_public())['html'];
    assert_true(!str_contains($html, '<footer') && !str_contains($html, '<header'), 'hidden chrome removes tenant header/footer');
    assert_true(str_contains($html, '<div class="sb-platform-signature">Powered by Kohevo</div></body>'), 'the platform signature slot is still rendered, last, outside tenant regions');

    $inherit = sbp4u_render($p, sbp4u_doc([sbp4u_block('core.heading', ['text' => 'x'])]), sbp4u_public())['html'];
    assert_true(str_contains($inherit, '<header class="sb-site-header">') && str_contains($inherit, 'Acme Studio'), 'inherit without a published partial falls back to built-in tenant-branded chrome');
    assert_true(strpos($inherit, '<div class="sb-platform-signature">') > strpos($inherit, '</footer>'), 'signature follows the tenant footer');

    $whiteLabel = sbp4u_pipeline(['signature' => static fn(): string => '']);
    assert_true(!str_contains(sbp4u_render($whiteLabel, $doc, sbp4u_public())['html'], '<div class="sb-platform-signature">'), 'an empty signature (licensed white-label) leaves no slot');
});

// ── Cache ────────────────────────────────────────────────────────────────────

unit('phase4 cache: mode policies and tenant/revision-safe keys', function (): void {
    $pub = StudioCachePolicy::headersFor(RenderMode::Public, str_repeat('a', 64));
    assert_eq('public, no-cache', $pub['Cache-Control']);
    assert_eq('"' . str_repeat('a', 64) . '"', $pub['ETag']);
    assert_true(!isset($pub['X-Robots-Tag']));

    foreach ([RenderMode::Preview, RenderMode::Editor] as $mode) {
        $h = StudioCachePolicy::headersFor($mode, str_repeat('a', 64));
        assert_eq('private, no-store, max-age=0', $h['Cache-Control'], 'preview/editor are never stored');
        assert_eq('noindex, nofollow', $h['X-Robots-Tag']);
        assert_true(!isset($h['ETag']), 'no ETag for private renders');
    }

    $hash = str_repeat('b', 64);
    $k1 = StudioCacheKey::published(101, 'default', 5, 9, $hash);
    assert_true($k1 !== StudioCacheKey::published(202, 'default', 5, 9, $hash), 'tenant is part of the key');
    assert_true($k1 !== StudioCacheKey::published(101, 'default', 5, 10, $hash), 'the published revision is part of the key');
    assert_true($k1 !== StudioCacheKey::published(101, 'default', 5, 9, str_repeat('c', 64)), 'the content hash is part of the key');
    assert_true($k1 !== StudioCacheKey::published(101, 'other', 5, 9, $hash), 'the site is part of the key');
    assert_throws(\InvalidArgumentException::class, static fn() => StudioCacheKey::published(0, 'default', 5, 9, $hash));
    assert_throws(\InvalidArgumentException::class, static fn() => StudioCacheKey::published(101, 'default', 5, 0, $hash), 'no key without a published revision');
    $etag = StudioCacheKey::etag($k1, '<html>');
    assert_true(preg_match('/^[a-f0-9]{64}$/', $etag) === 1 && !str_contains($etag, '101'), 'the ETag reveals no internal id');

    $p = sbp4u_pipeline();
    $doc = sbp4u_doc([sbp4u_block('core.heading', ['text' => 'x'])]);
    $public = $p['tenants']->runAs(SBP4U_TENANT, static fn() => $p['compiler']->fingerprint(sbp4u_page(), sbp4u_revision($doc), sbp4u_public()));
    $preview = $p['tenants']->runAs(SBP4U_TENANT, static fn() => $p['compiler']->fingerprint(sbp4u_page(), sbp4u_revision($doc), sbp4u_preview()));
    assert_true($public !== $preview, 'preview and public compilations can never share a content hash');
    $edited = $doc;
    $edited['sections'][0]['blocks'][0]['props']['text'] = 'y';
    $publicEdited = $p['tenants']->runAs(SBP4U_TENANT, static fn() => $p['compiler']->fingerprint(sbp4u_page(), sbp4u_revision($edited), sbp4u_public()));
    assert_true($public !== $publicEdited, 'any document change changes the content hash');
});

// ── Routing ──────────────────────────────────────────────────────────────────

unit('phase4 routing: only single-segment, non-reserved slugs are Studio candidates', function (): void {
    $runtime = StudioRuntimeFactory::build(['known_prefixes' => static fn(): array => ['forms', 'book', 'member/membership', 'custom-plugin']])->publicRuntime;
    assert_eq('about-us', $runtime->slugFromPath('/about-us'));
    assert_eq('about-us', $runtime->slugFromPath('about-us/'));
    foreach (['', '/', 'admin', 'forms', 'book', 'booking', 'membership', 'member', 'api', 'shop', 'custom-plugin', 'about/team', '../etc', 'About', 'a_b', 'x.php', 'uploads'] as $path) {
        assert_null($runtime->slugFromPath($path), "path '{$path}' must never be claimed by Studio");
    }
});

unit('phase4 stylesheet: the generic link colour never reaches .sb-button links, so every button variant keeps its own label colour (a primary label was invisible: accent text on an accent background)', function (): void {
    $css = \Slate\Module\StudioBuilder\Render\StudioStylesheet::css();
    assert_true(!str_contains($css, '.sb-body a{'), 'no bare link rule that also matches buttons');
    assert_true(str_contains($css, '.sb-body a:not(.sb-button){color:var(--sb-color-accent)}'), 'ordinary links keep the accent colour');
    assert_true(str_contains($css, '.sb-button--primary{background:var(--sb-surface-accent);color:var(--sb-text-inverse)}'), 'primary: inverse text on the accent surface');
    assert_true(str_contains($css, '.sb-button--secondary{background:var(--sb-surface-secondary);color:var(--sb-text-primary)'), 'secondary keeps its own text colour');
    assert_eq('3', \Slate\Module\StudioBuilder\Render\StudioStylesheet::VERSION, 'stylesheet version bumped so compiled pages are rebuilt');
});

if (!empty($studioP4UnitStandalone)) {
    exit(unit_summary());
}
