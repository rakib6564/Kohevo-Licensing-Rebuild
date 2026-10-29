<?php
/**
 * Kohevo Studio (studio-builder) — `content.posts` Data Provider.
 *
 * Provides query-driven blog and article content for Studio pages, query loops,
 * and dynamic archives. Strictly tenant-scoped, bounded (max 50 rows),
 * and parameter-validated.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Runtime\PublishedPageData;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class PostsProvider implements PublicDataProviderInterface
{
    public const MAX_RESULTS = 50;

    /** Rows read per query, and the most read for one request (a page deeper than that comes back short). */
    public const BATCH = 100;
    public const MAX_SCANNED = 2000;

    public function key(): string
    {
        return 'content.posts';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'source', 'type' => 'enum', 'label' => 'Source', 'required' => false, 'allowed_values' => ['posts', 'pages', 'custom'], 'default' => 'posts'],
            ['key' => 'category', 'type' => 'string', 'label' => 'Category filter', 'required' => false, 'default' => '', 'max_length' => 80],
            ['key' => 'tag', 'type' => 'string', 'label' => 'Tag filter', 'required' => false, 'default' => '', 'max_length' => 80],
            ['key' => 'author_id', 'type' => 'number', 'label' => 'Author ID', 'required' => false, 'default' => 0, 'integer_only' => true],
            ['key' => 'search', 'type' => 'string', 'label' => 'Search keyword', 'required' => false, 'default' => '', 'max_length' => 120],
            ['key' => 'order_by', 'type' => 'enum', 'label' => 'Order field', 'required' => false, 'allowed_values' => ['published_at', 'title', 'created_at'], 'default' => 'published_at'],
            ['key' => 'order', 'type' => 'enum', 'label' => 'Sort direction', 'required' => false, 'allowed_values' => ['desc', 'asc'], 'default' => 'desc'],
            ['key' => 'limit', 'type' => 'number', 'label' => 'Items per page', 'required' => false, 'default' => 6, 'min' => 1, 'max' => 50, 'integer_only' => true],
            ['key' => 'page', 'type' => 'number', 'label' => 'Page number', 'required' => false, 'default' => 1, 'min' => 1, 'integer_only' => true],
            ['key' => 'featured_only', 'type' => 'boolean', 'label' => 'Featured posts only', 'required' => false, 'default' => false],
        ]);
    }

    public function requiredEntitlement(): ?string
    {
        return null;
    }

    public function requiredPermission(): string
    {
        return StudioPermissions::VIEW;
    }

    public function maxResults(): int
    {
        return self::MAX_RESULTS;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        $tenantId = $tenants->id();
        if ($tenantId <= 0) {
            return [];
        }

        $limit    = max(1, min(self::MAX_RESULTS, (int) ($params['limit'] ?? 6)));
        $page     = max(1, (int) ($params['page'] ?? 1));
        $offset   = ($page - 1) * $limit;
        $orderBy  = in_array($params['order_by'] ?? null, ['published_at', 'title', 'created_at'], true) ? (string) $params['order_by'] : 'published_at';
        $order    = strtolower((string) ($params['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $authorId = (int) ($params['author_id'] ?? 0);
        $search   = trim((string) ($params['search'] ?? ''));
        $category = trim((string) ($params['category'] ?? ''));

        // Only what is LIVE: the published revision's settings and SEO (the row's columns mirror the draft),
        // never a theme template's backing page (its `template-…` address is not a page), and SQL does the
        // tenant, type, author, search and order work before anything is decoded.
        $sql = "SELECT p.id, p.title, p.slug, p.published_at, p.created_at, p.created_by,
                       p.published_revision_id, p.active_draft_revision_id, p.seo_json, p.settings_json, u.name AS author_name
                FROM studiobuilder_pages p
                LEFT JOIN users u ON u.id = p.created_by
                WHERE p.tenant_id = ?
                  AND p.status = 'published'
                  AND p.published_revision_id IS NOT NULL
                  AND p.page_type IN ('page', 'landing')
                  AND p.slug NOT LIKE 'template-%'";

        $sqlParams = [$tenantId];

        if ($authorId > 0) {
            $sql .= ' AND p.created_by = ?';
            $sqlParams[] = $authorId;
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $sql .= ' AND (p.title LIKE ? OR p.slug LIKE ?)';
            $sqlParams[] = $like;
            $sqlParams[] = $like;
        }

        $sql .= " ORDER BY p.{$orderBy} {$order}, p.id {$order}";

        // Category and featured live in the published settings, so they are filtered after decoding. Rows are
        // read in batches and reading stops once this page is filled, so page 1 never reads the whole site;
        // a very deep page stops after MAX_SCANNED rows.
        $want = $offset + $limit;
        $matched = [];
        for ($scanned = 0; $scanned < self::MAX_SCANNED && count($matched) < $want; $scanned += self::BATCH) {
            $rawRows = Database::rows($sql . ' LIMIT ' . self::BATCH . ' OFFSET ' . $scanned, $sqlParams);
            if ($rawRows === []) {
                break;
            }
            $published = PublishedPageData::forRows($tenantId, $rawRows);
            foreach ($rawRows as $row) {
                $live = $published[(int) $row['id']] ?? null;
                if ($live === null) {
                    continue;
                }
                $settings = $live['settings'];
                if (!empty($settings['is_theme_template']) || (is_string($settings['template_type'] ?? null) && $settings['template_type'] !== '')) {
                    continue;
                }
                $postCategory = (string) ($settings['category'] ?? 'General');
                if ($category !== '' && strcasecmp($postCategory, $category) !== 0) {
                    continue;
                }
                if (!empty($params['featured_only']) && empty($settings['featured'])) {
                    continue;
                }
                $matched[] = [$row, $live['seo'], $settings, $postCategory];
                if (count($matched) >= $want) {
                    break;
                }
            }
            if (count($rawRows) < self::BATCH) {
                break;
            }
        }

        $out = [];
        foreach (array_slice($matched, $offset, $limit) as [$row, $seo, $settings, $postCategory]) {
            $pubDate = !empty($row['published_at']) ? (string) $row['published_at'] : (string) $row['created_at'];
            $timestamp = strtotime($pubDate) ?: time();

            // The SEO description when it has text, else the page's own excerpt (an empty description is the default).
            $description = trim(is_string($seo['description'] ?? null) ? $seo['description'] : '');
            $excerpt = $description !== '' ? $description : (is_string($settings['excerpt'] ?? null) ? $settings['excerpt'] : '');
            if ($excerpt === '') {
                $excerpt = str_replace('%s', (string) $row['title'], \Slate\Module\StudioBuilder\Render\Html::t('studio_read_full_story', 'Read the full story: %s'));
            }

            $out[] = [
                'id'             => (int) $row['id'],
                'title'          => (string) $row['title'],
                'slug'           => (string) $row['slug'],
                'url'            => \Slate\Module\StudioBuilder\Meta\PageRoutes::path($tenantId, (int) $row['id'], (string) $row['slug'], ''),
                'excerpt'        => mb_substr($excerpt, 0, 200, 'UTF-8'),
                'category'       => $postCategory,
                'author_name'    => (string) ($row['author_name'] ?? 'Staff Author'),
                'author_id'      => (int) ($row['created_by'] ?? 1),
                'published_at'   => $pubDate,
                'date_formatted' => \Slate\Module\StudioBuilder\Render\Html::date($timestamp),
                'featured_image' => self::featuredImage($settings, is_array($seo) ? $seo : [], $tenants),
            ];
        }

        return $out;
    }

    /**
     * The card image of a post: its social-share (Open Graph) image, which the SEO panel sets per page. An older
     * document's `featured_image_url` setting still wins; the document schema no longer accepts that key.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $seo
     */
    private static function featuredImage(array $settings, array $seo, TenantContext $tenants): string
    {
        $legacy = (string) ($settings['featured_image_url'] ?? '');
        if ($legacy !== '') {
            return $legacy;
        }
        $mediaId = $seo['og_image_media_id'] ?? null;
        if (!is_int($mediaId) || $mediaId <= 0) {
            return '';
        }
        return (new CoreMediaResolver($tenants))->resolveImage($mediaId)?->url ?? '';
    }
}
