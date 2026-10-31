<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Theme Builder template matching (gap report, Low tier):
 * tie-breaks between templates (TB-10), misspelled actions (TB-12), the published page's category and tags
 * (TB-01 residual), and the archive/search/404 gate (TB-11, TB-13).
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
    $studioTplMatchUnitStandalone = true;
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Runtime\StudioPublicRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
use Slate\Module\StudioBuilder\Theme\TemplateConditionMatcher;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;
use Slate\Tenancy\TenantContext;

/** Pages repository fake: published rows of a type, in title order as PageRepository returns them. */
function sbtm_pages(array $rows): object
{
    return new class($rows) {
        public int $queries = 0;
        public function __construct(public array $rows) {}
        public function publishedOfType(string $type, int $limit = 5000): array
        {
            $this->queries++;
            $out = array_values(array_filter($this->rows, static fn(array $r): bool => ($r['page_type'] ?? '') === $type && ($r['status'] ?? '') === 'published'));
            usort($out, static fn(array $a, array $b): int => [(string) $a['title'], (int) $a['id']] <=> [(string) $b['title'], (int) $b['id']]);
            return $out;
        }
        public function find(int $id): ?array
        {
            foreach ($this->rows as $r) {
                if ((int) $r['id'] === $id) {
                    return $r;
                }
            }
            return null;
        }
    };
}

/** Revisions fake: every published revision is `{settings: …}` of its row. */
function sbtm_revisions(array $rows): object
{
    return new class($rows) {
        public function __construct(private array $rows) {}
        public function findByIdForPage(int $pageId, int $revId): ?array
        {
            foreach ($this->rows as $r) {
                if ((int) $r['id'] === $pageId && (int) $r['published_revision_id'] === $revId) {
                    return ['id' => $revId, 'page_id' => $pageId, 'document_json' => json_encode(['settings' => json_decode((string) $r['settings_json'], true), 'containers' => []])];
                }
            }
            return null;
        }
    };
}

function sbtm_page(): PageAddress
{
    return PageAddress::fromRow(['id' => 10, 'uuid' => '10000000-0000-4000-8000-000000000010', 'title' => 'P', 'slug' => 'p', 'page_type' => 'page',
        'status' => 'published', 'route_mode' => 'standalone', 'published_revision_id' => 50]);
}

unit('theme templates: a tie between equally specific templates goes to the most recently published, never to the title (TB-10)', function (): void {
    $site = json_encode(['template_type' => 'header', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'entire_site']]]]);
    $rows = [
        ['id' => 21, 'title' => 'Alpha header', 'slug' => 'alpha', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 121, 'published_at' => '2026-10-01 09:00:00', 'settings_json' => $site],
        ['id' => 22, 'title' => 'Zulu header',  'slug' => 'zulu',  'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 122, 'published_at' => '2026-10-05 09:00:00', 'settings_json' => $site],
    ];
    $pages = sbtm_pages($rows);
    $resolver = new ThemeTemplateResolver($pages, sbtm_revisions($rows));
    assert_eq(122, $resolver->resolveHeader(sbtm_page(), ['category' => ''])->revisionId, 'the header published last wins the tie');

    // Swapping the titles does not change the live header.
    $pages->rows[0]['title'] = 'Zulu header';
    $pages->rows[1]['title'] = 'Alpha header';
    assert_eq(122, $resolver->resolveHeader(sbtm_page(), ['category' => ''])->revisionId, 'renaming a template does not flip the winner');

    // Published in the same second: the newer template (higher id), whatever the titles.
    $pages->rows[0]['published_at'] = $pages->rows[1]['published_at'];
    $pages->rows[0]['title'] = 'Alpha header';
    $pages->rows[1]['title'] = 'Zulu header';
    assert_eq(122, $resolver->resolveHeader(sbtm_page(), ['category' => ''])->revisionId, 'same publish time: the higher id');

    // The more specific template still beats a newer, wider one.
    $tech = json_encode(['template_type' => 'header', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'category', 'value' => 'Tech']]]]);
    $pages->rows[0]['settings_json'] = $tech;
    $resolver = new ThemeTemplateResolver($pages, sbtm_revisions($pages->rows));
    assert_eq(121, $resolver->resolveHeader(sbtm_page(), ['category' => 'Tech'])->revisionId, 'specificity first');

    // Archive, search and 404 templates break ties the same way.
    $archive = json_encode(['template_type' => 'archive']);
    $tpl = [
        ['id' => 31, 'title' => 'Archive A', 'slug' => 'archive-a', 'page_type' => 'system', 'status' => 'published', 'published_revision_id' => 131, 'published_at' => '2026-10-02 09:00:00', 'settings_json' => $archive],
        ['id' => 32, 'title' => 'Archive B', 'slug' => 'archive-b', 'page_type' => 'system', 'status' => 'published', 'published_revision_id' => 132, 'published_at' => '2026-10-03 09:00:00', 'settings_json' => $archive],
    ];
    $tplPages = sbtm_pages($tpl);
    $resolver = new ThemeTemplateResolver($tplPages, sbtm_revisions($tpl));
    assert_eq(32, (int) $resolver->resolveArchive('category', 'News')['page']['id'], 'the archive template published last');
    $tplPages->rows[0]['title'] = 'Archive Z';
    assert_eq(32, (int) $resolver->resolveArchive('category', 'News')['page']['id'], 'and still after a rename');
});

unit('display conditions: a misspelled action never includes a page, and is refused on save (TB-12)', function (): void {
    $ctx = ['category' => 'Secret', 'content_type' => 'page'];
    assert_eq(0, TemplateConditionMatcher::score([['action' => 'excludes', 'condition' => 'entire_site']], $ctx), '"excludes" is not an inclusion');
    assert_eq(0, TemplateConditionMatcher::score([['type' => 'exlcude', 'condition' => 'category', 'value' => 'Secret']], $ctx), 'nor is a misspelled older "type" next to a condition');
    assert_eq(TemplateConditionMatcher::SCORE_GLOBAL, TemplateConditionMatcher::score([
        ['action' => 'include', 'condition' => 'entire_site'],
        ['action' => 'excludes', 'condition' => 'category', 'value' => 'Secret'],
    ], $ctx), 'the misspelled rule is ignored; the valid one still applies');
    assert_eq(0, TemplateConditionMatcher::score([['type' => 'categroy', 'value' => 'Secret']], $ctx), 'a misspelled scope in "type" matches nothing');

    // The spellings stored today keep working.
    assert_eq(TemplateConditionMatcher::SCORE_SPECIFIC, TemplateConditionMatcher::score([['type' => 'include', 'condition' => 'category', 'value' => 'Secret']], $ctx), 'type=include with a condition');
    assert_eq(TemplateConditionMatcher::SCORE_SPECIFIC, TemplateConditionMatcher::score([['type' => 'category', 'value' => 'Secret']], $ctx), 'a scope named by type');
    assert_eq(TemplateConditionMatcher::SCORE_SPECIFIC, TemplateConditionMatcher::score([['scope' => 'category', 'value' => 'Secret']], $ctx), 'no action at all is an include');
    assert_eq(0, TemplateConditionMatcher::score([['action' => 'include', 'condition' => 'entire_site'], ['action' => 'EXCLUDE', 'condition' => 'category', 'value' => 'Secret']], $ctx), 'action case does not matter');

    $registry = BlockRegistry::withCoreFoundationBlocks();
    $valid = static function (array $rules) use ($registry): bool {
        $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Test Page');
        $doc['settings']['conditions'] = ['rules' => $rules];
        return DocumentValidator::validate($doc, $registry)->isValid();
    };
    assert_false($valid([['action' => 'excludes', 'condition' => 'category', 'value' => 'News']]), 'action "excludes" is refused');
    assert_false($valid([['type' => 'categroy', 'value' => 'News']]), 'type "categroy" is refused');
    assert_false($valid([['type' => 'category', 'condition' => 'category', 'value' => 'News']]), 'beside a condition, type can only be include or exclude');
    assert_true($valid([['action' => 'exclude', 'condition' => 'category', 'value' => 'News']]), 'action exclude');
    assert_true($valid([['type' => 'include', 'condition' => 'singular', 'value' => 'post']]), 'type include, as the admin screen writes it');
    assert_true($valid([['type' => 'category', 'value' => 'News']]), 'a known scope named by type');
});

unit('header and footer conditions read the published page, never its draft category or tags (TB-01 residual)', function (): void {
    $byCategory = json_encode(['template_type' => 'header', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'category', 'value' => 'A']]]]);
    $byTag      = json_encode(['template_type' => 'header', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'tag', 'value' => 'launch']]]]);
    $rows = [
        ['id' => 41, 'title' => 'Category A header', 'slug' => 'cat-a', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 141, 'settings_json' => $byCategory],
        ['id' => 42, 'title' => 'Launch header', 'slug' => 'launch', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 142, 'settings_json' => $byTag],
        // The page itself: published with no category or tags; its unpublished draft (mirrored in settings_json) sets both.
        ['id' => 10, 'title' => 'P', 'slug' => 'p', 'page_type' => 'page', 'status' => 'published', 'published_revision_id' => 50, 'active_draft_revision_id' => 51,
         'created_by' => 7, 'settings_json' => json_encode(['category' => 'A', 'tags' => ['launch']])],
    ];
    $resolver = new ThemeTemplateResolver(sbtm_pages($rows), sbtm_revisions($rows));
    assert_null($resolver->resolveHeader(sbtm_page(), []), 'a draft category or tag must not pick the live header');
    assert_null($resolver->resolveHeader(sbtm_page(), ['category' => '', 'tags' => []]), 'the compiler passes the published (empty) values');
    assert_eq(141, $resolver->resolveHeader(sbtm_page(), ['category' => 'A', 'tags' => []])->revisionId, 'the published category');
    assert_eq(142, $resolver->resolveHeader(sbtm_page(), ['category' => '', 'tags' => ['launch']])->revisionId, 'the published tags');

    // The compiler reads the tags of the revision it renders: a list, or one comma-separated text.
    $tagsOf = new \ReflectionMethod(\Slate\Module\StudioBuilder\Render\Compile\StudioCompiler::class, 'tagsOf');
    assert_eq(['launch', 'news'], $tagsOf->invoke(null, ['settings' => ['tags' => ['launch', ' news ', 3, '']]]), 'a list of text');
    assert_eq(['launch', 'news'], $tagsOf->invoke(null, ['settings' => ['tags' => 'launch, news']]), 'comma-separated text');
    assert_eq([], $tagsOf->invoke(null, ['settings' => []]), 'none');
});

/** A public runtime over a fake theme resolver; the renderer is never reached by these tests. */
function sbtm_runtime(object $pages, bool $entitled): StudioPublicRuntime
{
    $tenants = new TenantContext();
    $renderer = (new \ReflectionClass(StudioRenderService::class))->newInstanceWithoutConstructor();
    return new StudioPublicRuntime($tenants, new PageRepository($tenants), $renderer, new StudioReservedRoutes(), static fn(): bool => $entitled, null,
        new ThemeTemplateResolver($pages, sbtm_revisions([])));
}

unit('archive, search and 404 templates are looked up only for a licensed tenant, and a lookup error is a plain 404 (TB-11)', function (): void {
    $throwing = new class {
        public int $queries = 0;
        public function publishedOfType(string $type, int $limit = 5000): array
        {
            $this->queries++;
            throw new \RuntimeException('pages table offline');
        }
    };
    $tenants = new TenantContext();
    $tenants->runAs(5, function () use ($throwing): void {
        $unlicensed = sbtm_runtime($throwing, false);
        assert_null($unlicensed->handleArchive('category', 'x'), 'unlicensed: no archive');
        assert_null($unlicensed->handleSearch('x'), 'unlicensed: no search page');
        assert_null($unlicensed->handleNotFound(), 'unlicensed: no 404 template');
        assert_eq(0, $throwing->queries, 'and no template query ran');

        $licensed = sbtm_runtime($throwing, true);
        assert_null($licensed->handleArchive('category', 'x'), 'a failing lookup is the ordinary 404, not an exception');
        assert_null($licensed->handleSearch('x'), 'for search too');
        assert_null($licensed->handleNotFound(), 'and the 404 template');
        assert_true($throwing->queries > 0, 'the licensed tenant did look');
    });
});

unit('an archive address with an over-long, empty or malformed term is the ordinary 404 (TB-13)', function (): void {
    $pages = sbtm_pages([]);
    $tenants = new TenantContext();
    $tenants->runAs(5, function () use ($pages): void {
        $rt = sbtm_runtime($pages, true);
        foreach ([str_repeat('a', 192), "\x01\x02", "  ", "\xff\xfe"] as $term) {
            assert_null($rt->handleArchive('category', $term), 'refused: ' . bin2hex(substr($term, 0, 8)));
        }
        assert_null($rt->handlePath('/category/' . str_repeat('b', 300)), 'refused from the address as well');
        assert_eq(0, $pages->queries, 'no template lookup for a term that cannot exist');

        assert_null($rt->handleArchive('category', str_repeat('a', 191)), 'a 191-character term is looked up (no template here)');
        assert_null($rt->handlePath('/category/Open%20Source'), 'an ordinary term is looked up');
        assert_eq(4, $pages->queries, 'two lookups, each over the system and page types');
    });
});

unit('the editor learns which published templates share these conditions, and whether a previewed page would use one (TB-10, TB-14)', function (): void {
    assert_eq('header', ThemeTemplateResolver::roleOf('header_partial', [], 'x'));
    assert_eq('footer', ThemeTemplateResolver::roleOf('footer_partial', [], 'default'));
    assert_eq('single', ThemeTemplateResolver::roleOf('system', ['template_type' => 'single'], 'x'));
    assert_eq('404', ThemeTemplateResolver::roleOf('system', ['template_type' => 'not_found'], 'x'));
    assert_eq('archive', ThemeTemplateResolver::roleOf('system', [], 'theme-archive'));
    assert_null(ThemeTemplateResolver::roleOf('page', [], 'about'), 'an ordinary page has no role');
    assert_null(ThemeTemplateResolver::roleOf('global_component', ['template_type' => 'single'], 'x'));

    $site = ['template_type' => 'header', 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'entire_site']]]];
    $rows = [
        ['id' => 51, 'title' => 'Site header', 'slug' => 'site', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 151, 'published_at' => '2026-10-01 09:00:00', 'settings_json' => json_encode($site)],
        ['id' => 52, 'title' => 'Default header', 'slug' => 'default', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 152, 'settings_json' => json_encode($site)],
        ['id' => 53, 'title' => 'This one', 'slug' => 'mine', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 153, 'settings_json' => json_encode($site)],
        ['id' => 54, 'title' => 'Legacy', 'slug' => 'legacy', 'page_type' => 'header_partial', 'status' => 'published', 'published_revision_id' => 154, 'settings_json' => json_encode(['category' => 'News'])],
        ['id' => 61, 'title' => 'Archive', 'slug' => 'archive', 'page_type' => 'system', 'status' => 'published', 'published_revision_id' => 161, 'settings_json' => json_encode(['template_type' => 'archive'])],
        ['id' => 62, 'title' => 'Search', 'slug' => 'search', 'page_type' => 'system', 'status' => 'published', 'published_revision_id' => 162, 'settings_json' => json_encode(['template_type' => 'search'])],
    ];
    $resolver = new ThemeTemplateResolver(sbtm_pages($rows), sbtm_revisions($rows));
    $peers = $resolver->peers('header', 53);
    assert_eq([54, 51], array_column($peers, 'id'), 'other published headers, in title order; not itself and not the site default');
    assert_eq($site['conditions'], $peers[1]['conditions'], 'with the conditions they were published with');
    assert_eq([['scope' => 'category', 'value' => 'News']], $peers[0]['conditions'], 'an older category-only header shows as its category rule');
    assert_eq('2026-10-01 09:00:00', $peers[1]['published_at']);
    assert_eq([61], array_column($resolver->peers('archive', 0), 'id'), 'templates of the same role only');

    $page = sbtm_page();
    $tech = ['conditions' => ['rules' => [['action' => 'include', 'condition' => 'category', 'value' => 'Tech']]]];
    assert_true(ThemeTemplateResolver::appliesTo('header', 'tech', $tech, $page, ['category' => 'Tech']), 'matching conditions');
    assert_false(ThemeTemplateResolver::appliesTo('header', 'tech', $tech, $page, ['category' => 'Design']), 'conditions that do not match the page');
    assert_false(ThemeTemplateResolver::appliesTo('footer', 'plain', [], $page), 'a header or footer with no conditions is never chosen by them');
    assert_true(ThemeTemplateResolver::appliesTo('footer', 'default', [], $page), 'the site default applies wherever nothing else does');
    assert_true(ThemeTemplateResolver::appliesTo('header', 'legacy', ['category' => 'News'], $page, ['category' => 'news']), 'an older category-only header');
    assert_true(ThemeTemplateResolver::appliesTo('single', 'single', [], $page), 'a single template with no conditions applies everywhere');
    assert_false(ThemeTemplateResolver::appliesTo('single', 'single', $tech, $page, ['category' => '']), 'one whose conditions do not match does not');
});

if (!empty($studioTplMatchUnitStandalone)) {
    exit(unit_summary());
}
