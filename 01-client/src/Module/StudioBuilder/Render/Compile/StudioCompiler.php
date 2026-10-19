<?php
/**
 * Kohevo Studio (studio-builder) — Page compiler.
 *
 *   revision.document_json
 *     -> validate / normalize            (RenderDocumentPreparer)
 *     -> template/chrome resolution      (ChromeResolver: published partials)
 *     -> global component resolution     (GlobalComponentResolver: published components)
 *     -> theme resolution                (ThemeResolver: defaults < branding < tokens)
 *     -> block rendering                 (DocumentRenderer; dynamic nodes deferred)
 *     -> chrome assembly + SEO data      (SeoHead)
 *     -> CompiledPage (derived artifact)
 *
 * `compile()` is pure: it reads, never writes. `publishedArtifact()` is the
 * public-runtime cache: it reuses the stored `published` compilation only while
 * it is provably current — same source revision, same `content_hash` over every
 * compile input, same compiler version, and younger than
 * MAX_ARTIFACT_AGE_SECONDS (a bounded backstop for inputs that raise no change
 * event, e.g. an edited media row) — and otherwise recompiles from the revision.
 * Only `published` artifacts are ever persisted; preview/editor renders never
 * touch the compilations table.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Exception\StudioRenderException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeSource;
use Slate\Module\StudioBuilder\Render\Component\ComponentSource;
use Slate\Module\StudioBuilder\Render\Component\GlobalComponentResolver;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderDocumentPreparer;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Tenancy\TenantContext;

final class StudioCompiler
{
    // 1.0.1 (Phase 9B): SeoHead drops a site-relative og:image when no base URL is configured.
    public const COMPILER_VERSION        = '1.3.0';
    public const MODE_PUBLISHED          = 'published';
    public const MAX_ARTIFACT_AGE_SECONDS = 3600;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly BlockRegistry $registry,
        private readonly BlockRendererRegistry $renderers,
        private readonly DocumentRenderer $documents,
        private readonly ThemeResolver $themes,
        private readonly ChromeResolver $chrome,
        private readonly MediaResolverInterface $media,
        private readonly ?CompilationRepository $compilations = null,
        ?GlobalComponentResolver $components = null,
    ) {
        $this->components = $components ?? new GlobalComponentResolver();
    }

    /** Phase 6: resolves `section.global_ref` to the referenced component's published revision. */
    private readonly GlobalComponentResolver $components;

    /**
     * Compile one revision of one page for a render context. Reads only.
     *
     * @param array<string, mixed> $revision a `studiobuilder_revisions` row of $page
     */
    public function compile(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        $this->assertScope($page, $revision, $context);
        $plan = $this->plan($page, $revision, $context);

        try {
            $prepared  = RenderDocumentPreparer::prepare((string) $revision['document_json'], $this->registry);
            $document  = $prepared['document'];
            $theme     = $plan['theme'];
            $collector = new RenderCollector();

            $header = $this->renderChrome('header', $plan['header'], $context, $theme, $collector);
            $single = $plan['single'] !== null ? $this->renderSingle($plan['single'], $document, $plan['components'], $context, $theme, $collector) : null;
            $main   = $single['html'] ?? $this->documents->renderSections($document['sections'] ?? [], $context, $theme, $collector, true, $this->prepareComponents($plan['components']));
            $footer = $this->renderChrome('footer', $plan['footer'], $context, $theme, $collector);

            $width = $single['width'] ?? ($document['settings']['container_width'] ?? 'wide');
            $width = in_array($width, CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true) ? $width : 'wide';

            // A header or footer opened on its own (canvas, preview) sits in the same wrapper it has on a live page, so
            // the site's CSS for its header or footer applies, and none meant for page content (section spacing) does.
            $region = ['header_partial' => 'header', 'footer_partial' => 'footer'][$page->pageType] ?? null;
            $html = $region !== null && $single === null
                ? $header . '<' . $region . ' class="sb-site-' . $region . ' sb-site-' . $region . '--partial" id="main">' . $main . '</' . $region . '>' . $footer
                : $header . '<main class="sb-main sb-main--' . $width . '" id="main">' . $main . '</main>' . $footer;
            $css  = StudioStylesheet::css() . $theme->rootCss() . $collector->css();
            $seo  = SeoHead::build(is_array($document['seo'] ?? null) ? $document['seo'] : [], $page, $context->site, $context->mode, $this->media);
        } catch (StudioRenderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StudioRenderException('Studio page compilation failed.', ['reason' => 'compiler_failure', 'page_id' => $page->id], $e);
        }

        return new CompiledPage(
            pageId: (int) $page->id,
            revisionId: (int) $revision['id'],
            compileMode: self::MODE_PUBLISHED,
            html: $html,
            css: $css,
            dynamicManifest: [
                'nodes' => $single !== null ? array_replace($collector->deferred(), $single['replaced']) : $collector->deferred(),
                'nonce' => $collector->nonce,
                'theme' => ['group' => $theme->tokenGroup, 'tokens' => $theme->tokens()],
            ],
            headAssets: [
                'degraded'             => $prepared['degraded'],
                'document_fingerprint' => hash('sha256', (string) $revision['document_json']),
                'inputs'               => $plan['inputs'],
                'seo'                  => $seo->toArray(),
            ],
            contentHash: $plan['hash'],
            compilerVersion: self::COMPILER_VERSION,
        );
    }

    /**
     * The `content_hash` a compilation of this revision must carry to be current.
     *
     * @param array<string, mixed> $revision
     */
    public function fingerprint(PageAddress $page, array $revision, RenderContext $context): string
    {
        $this->assertScope($page, $revision, $context);
        return $this->plan($page, $revision, $context)['hash'];
    }

    /**
     * Public runtime: the current `published` artifact for the page's published
     * revision, recompiled and stored when missing or stale.
     *
     * @param array<string, mixed> $revision the page's PUBLISHED revision row
     */
    public function publishedArtifact(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        if (!$context->mode->isPublic()) {
            throw new \LogicException('Only public-context compilations may be persisted.');
        }
        if ($page->publishedRevisionId === null || (int) $revision['id'] !== $page->publishedRevisionId) {
            throw new \LogicException('publishedArtifact() requires the page\'s published revision.');
        }

        $expected = $this->fingerprint($page, $revision, $context);
        if ($this->compilations !== null) {
            $row = $this->compilations->findByPageAndMode((int) $page->id, self::MODE_PUBLISHED);
            if ($row !== null && $this->isCurrent($row, (int) $revision['id'], $expected)) {
                $stored = CompiledPage::fromRow($row);
                if ($stored !== null) {
                    return $stored;
                }
            }
        }

        $compiled = $this->compile($page, $revision, $context);
        $this->store($compiled);
        return $compiled;
    }

    /** Compile and persist the published artifact now (used inside the publish transaction). */
    public function compileAndStorePublished(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        if (!$context->mode->isPublic()) {
            throw new \LogicException('Only public-context compilations may be persisted.');
        }
        $compiled = $this->compile($page, $revision, $context);
        $this->store($compiled);
        return $compiled;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isCurrent(array $row, int $revisionId, string $expectedHash): bool
    {
        return (int) ($row['revision_id'] ?? 0) === $revisionId
            && hash_equals($expectedHash, (string) ($row['content_hash'] ?? ''))
            && (string) ($row['compiler_version'] ?? '') === self::COMPILER_VERSION
            && !$this->isExpired($row);
    }

    private function store(CompiledPage $compiled): void
    {
        if ($this->compilations === null) {
            return;
        }
        $row = $compiled->toRow();
        if (\function_exists('slate_db_now')) {
            $row['compiled_at'] = \slate_db_now(); // MySQL's own clock (anti-drift CLOCK)
        }
        $this->compilations->upsertForPage($compiled->pageId, $compiled->compileMode, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isExpired(array $row): bool
    {
        if (!\function_exists('slate_db_now')) {
            return false;
        }
        // Both timestamps come from the database clock; PHP only subtracts them.
        $now = strtotime(\slate_db_now() . ' UTC');
        $at  = strtotime((string) ($row['compiled_at'] ?? '') . ' UTC');
        if ($now === false || $at === false) {
            return true;
        }
        $age = $now - $at;
        return $age < -60 || $age > self::MAX_ARTIFACT_AGE_SECONDS;
    }

    /**
     * The published sections of every resolved component, prepared through
     * the same validate/normalize path as the page itself. Missing or
     * unpublished components are simply absent (→ unavailable fallback).
     *
     * @param array<string, ComponentSource> $sources
     * @return array<string, list<array<string, mixed>>>
     */
    private function prepareComponents(array $sources): array
    {
        $prepared = [];
        foreach ($sources as $ref => $source) {
            if (!$source->isPublished()) {
                continue;
            }
            try {
                $document = RenderDocumentPreparer::prepare((string) $source->documentJson, $this->registry)['document'];
            } catch (StudioRenderException $ignored) {
                continue; // an unrenderable component degrades to the unavailable fallback, never breaks the page
            }
            $prepared[(string) $ref] = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        }
        return $prepared;
    }

    /**
     * @param array<string, mixed> $revision
     * @return array{theme: ResolvedTheme, header: ChromeSource, footer: ChromeSource, components: array<string, ComponentSource>, inputs: array<string, mixed>, hash: string}
     */
    private function plan(PageAddress $page, array $revision, RenderContext $context): array
    {
        $documentJson = (string) $revision['document_json'];
        $settings = CanonicalDocumentSchema::defaultSettings();
        $decoded  = json_decode($documentJson, true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
        if (is_array($decoded) && is_array($decoded['settings'] ?? null)) {
            foreach ($decoded['settings'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $settings[$k] = $v;
                }
            }
        }
        if (!in_array($settings['header_mode'], CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true)) {
            $settings['header_mode'] = 'inherit';
        }
        if (!in_array($settings['footer_mode'], CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true)) {
            $settings['footer_mode'] = 'inherit';
        }

        $theme  = $this->themes->resolve((string) $settings['token_group']);
        // Header and footer display conditions read this revision's own category and tags, never the draft row.
        $chromeContext = $settings + ['category' => ''];
        $chromeContext['tags'] = self::tagsOf($decoded);
        $header = $this->chrome->resolve('header', (string) $settings['header_mode'], $page, $chromeContext);
        $footer = $this->chrome->resolve('footer', (string) $settings['footer_mode'], $page, $chromeContext);
        // "Preview as a page": an authoring preview may swap in the draft of a header or footer being edited. A page
        // that hides its header or footer (or gets no chrome at all) keeps it hidden, as on the live site.
        $override = $context->mode === RenderMode::Preview ? $context->attribute('chrome_override') : null;
        if (is_array($override) && ($override['header'] ?? null) instanceof ChromeSource && $header->kind !== ChromeSource::HIDDEN) {
            $header = $override['header'];
        }
        if (is_array($override) && ($override['footer'] ?? null) instanceof ChromeSource && $footer->kind !== ChromeSource::HIDDEN) {
            $footer = $override['footer'];
        }
        // Live references resolve to the referenced components' PUBLISHED revisions; their
        // identity is part of the fingerprint, so a republished component makes every
        // dependent artifact stale on the next request even without explicit invalidation.
        $components = is_array($decoded) && in_array((string) ($decoded['document_type'] ?? ''), CanonicalDocumentSchema::GLOBAL_REF_DOCUMENT_TYPES, true)
            ? $this->components->resolveDocument($decoded)
            : [];
        $componentKeys = [];
        foreach ($components as $ref => $source) {
            $componentKeys[(string) $ref] = $source->key();
        }

        // Theme Builder: a published Single template whose conditions match lays out this page. Its identity
        // and published revision are part of the fingerprint, so publishing the template rebuilds the page.
        $single = $this->singleFor($page, $settings, $context);

        $inputs = [
            'single'      => $single !== null ? (int) $single['page']['id'] . ':' . (int) $single['revision']['id'] : '',
            'compiler'    => self::COMPILER_VERSION,
            'components'  => $componentKeys,
            'document'    => hash('sha256', $documentJson),
            'footer'      => $footer->key(),
            'header'      => $header->key(),
            'menus'       => StudioMenus::fingerprint($context->tenantId),
            'mode'        => $context->mode->value,
            'page'        => hash('sha256', $page->slug . "\n" . $page->title . "\n" . $page->routeMode . "\n" . $page->pageType),
            'registry'    => ModuleBlockDefinitions::registryFingerprint($this->registry),
            'renderers'   => $this->renderers->fingerprint(),
            'revision_id' => (int) $revision['id'],
            'site'        => $context->site->fingerprint(),
            'stylesheet'  => StudioStylesheet::VERSION,
            'theme'       => $theme->fingerprint(),
        ];

        return [
            'theme'      => $theme,
            'header'     => $header,
            'footer'     => $footer,
            'components' => $components,
            'single'     => $single,
            'inputs'     => $inputs,
            'hash'       => hash('sha256', CanonicalJson::encode($inputs)),
        ];
    }

    /**
     * The tags of a decoded page document (`settings.tags`: a list of text, or one comma-separated text), at most 50.
     *
     * @return list<string>
     */
    private static function tagsOf(mixed $decoded): array
    {
        $tags = is_array($decoded) && is_array($decoded['settings'] ?? null) ? ($decoded['settings']['tags'] ?? []) : [];
        $tags = is_string($tags) ? explode(',', $tags) : (is_array($tags) ? $tags : []);
        $out = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $out[] = mb_substr(trim($tag), 0, 100);
            }
        }
        return array_slice($out, 0, 50);
    }

    /**
     * What the theme blocks of a page's Single template show about the page (title, address, category, author,
     * date), or [] when no Single template applies. Those blocks are dynamic, so the render service puts these
     * on the context the page is both compiled and filled with.
     *
     * @param array<string, mixed> $revision the page revision being rendered
     * @return array<string, mixed>
     */
    public function singleAttributes(PageAddress $page, array $revision, RenderContext $context): array
    {
        $decoded = json_decode((string) ($revision['document_json'] ?? ''), true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
        $settings = [];
        foreach ((is_array($decoded) && is_array($decoded['settings'] ?? null)) ? $decoded['settings'] : [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $settings[$k] = $v;
            }
        }
        if ($this->singleFor($page, $settings, $context) === null) {
            return [];
        }
        $author = '';
        try {
            $createdBy = (int) \Slate\Data\Database::value('SELECT created_by FROM studiobuilder_pages WHERE id = ? AND tenant_id = ?', [(int) $page->id, $context->tenantId]);
            if ($createdBy > 0) {
                $author = (string) (\Slate\Data\Database::value('SELECT name FROM users WHERE id = ? AND tenant_id = ?', [$createdBy, $context->tenantId]) ?? '');
            }
        } catch (\Throwable) {
            // No author line rather than a wrong one.
        }
        $published = $page->publishedAt !== null ? strtotime($page->publishedAt) : false;
        return [
            'post_title'  => $page->title,
            'post_url'    => $context->site->absoluteUrl($page->routeMode === 'homepage' ? '/' : '/' . $page->slug),
            'category'    => $settings['category'] ?? '',
            'author_name' => mb_substr(trim($author), 0, 120),
            'date'        => $published !== false ? date('M j, Y', $published) : '',
            'is_single'   => true,
        ];
    }

    /**
     * The Single template for a page on a public or preview render, or null. Never in the editor (the canvas
     * edits the page's own document), never for a page that is itself a theme template.
     *
     * @param array<string, string> $settings the page document's string settings
     * @return ?array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    private function singleFor(PageAddress $page, array $settings, RenderContext $context): ?array
    {
        if ($context->mode === RenderMode::Editor || ($settings['template_type'] ?? '') !== '' || $page->id === null) {
            return null;
        }
        $conditionContext = [];
        if (($settings['category'] ?? '') !== '') {
            $conditionContext['category'] = $settings['category'];
        }
        try {
            $type = PageMeta::read($context->tenantId, (int) $page->id)['type'];
            if ($type !== '') {
                $conditionContext['content_type'] = $type;
            }
        } catch (\Throwable) {
            // No custom content type: the page type stands in for it.
        }
        try {
            return $this->chrome->single($page, $conditionContext);
        } catch (\Throwable $e) {
            StudioLog::failure('render', 'single_template', $e, 'warning');
            return null;
        }
    }

    /**
     * The page laid out by its Single template: the template's sections, with every Post content block replaced
     * by the page's own sections. Both render into one collector, so the page's live blocks are filled per
     * request like any other; the Post content placeholders are dropped from the dynamic manifest. Post title,
     * meta and other theme blocks stay dynamic and read the page from the render context
     * (StudioRenderService::singleAttributes()).
     *
     * @param array{page: array<string, mixed>, revision: array<string, mixed>} $single
     * @param array<string, mixed> $document the page's prepared document
     * @param array<string, ComponentSource> $components the page's resolved global components
     * @return array{html: string, width: string, replaced: array<int, null>}
     */
    private function renderSingle(array $single, array $document, array $components, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector): array
    {
        $templateJson = (string) ($single['revision']['document_json'] ?? '');
        $template = RenderDocumentPreparer::prepare($templateJson, $this->registry)['document'];
        $decoded = json_decode($templateJson, true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
        $templateComponents = is_array($decoded) ? $this->components->resolveDocument($decoded) : [];

        $first = count($collector->deferred());
        $html = $this->documents->renderSections($template['sections'] ?? [], $context, $theme, $collector, true, $this->prepareComponents($templateComponents));
        $slots = [];
        foreach (array_slice($collector->deferred(), $first, null, true) as $index => $node) {
            if (($node['type'] ?? '') === 'theme.post_content') {
                $slots[] = $index;
            }
        }
        $replaced = [];
        if ($slots !== []) {
            $body = '<div class="sb-block sb-block--theme-post-content sb-post-body">'
                . $this->documents->renderSections($document['sections'] ?? [], $context, $theme, $collector, true, $this->prepareComponents($components))
                . '</div>';
            foreach ($slots as $i => $index) {
                // The page's content appears once; a second Post content block in the template shows nothing.
                $html = str_replace(RenderCollector::marker($collector->nonce, $index), $i === 0 ? $body : '', $html);
                $replaced[$index] = null;
            }
        }
        $width = $template['settings']['container_width'] ?? 'wide';
        return [
            'html'     => $html,
            'width'    => in_array($width, CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true) ? $width : 'wide',
            'replaced' => $replaced,
        ];
    }

    private function renderChrome(string $region, ChromeSource $source, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector): string
    {
        if ($source->kind === ChromeSource::HIDDEN) {
            return '';
        }
        $tag   = $region === 'header' ? 'header' : 'footer';
        $class = 'sb-site-' . $region;

        if ($source->kind === ChromeSource::PARTIAL && $source->documentJson !== null) {
            $partial = RenderDocumentPreparer::prepare($source->documentJson, $this->registry)['document'];
            return '<' . $tag . ' class="' . $class . ' sb-site-' . $region . '--partial">'
                . $this->documents->renderSections($partial['sections'] ?? [], $context, $theme, $collector, true)
                . '</' . $tag . '>';
        }

        $site = $context->site;
        if ($region === 'header') {
            $logo = $site->logoUrl !== '' ? '<img src="' . Html::e($site->logoUrl) . '" alt="">' : '';
            return '<header class="sb-site-header"><a class="sb-site-brand" href="' . Html::e($site->absoluteUrl('/')) . '">'
                . $logo . '<span>' . Html::e($site->siteName) . '</span></a></header>';
        }
        return '<footer class="sb-site-footer"><p>&copy; ' . Html::e($site->siteName) . '</p></footer>';
    }

    /**
     * @param array<string, mixed> $revision
     */
    private function assertScope(PageAddress $page, array $revision, RenderContext $context): void
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0 || $this->tenants->id() !== $context->tenantId) {
            throw new StudioTenantScopeException();
        }
        if ($page->id === null || (int) ($revision['page_id'] ?? 0) !== $page->id || !isset($revision['id'], $revision['document_json'])) {
            throw new StudioRenderException('Revision does not belong to the page being rendered.', ['reason' => 'revision_page_mismatch']);
        }
    }
}
