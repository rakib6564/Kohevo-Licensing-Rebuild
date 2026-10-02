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
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class PostsProvider implements PublicDataProviderInterface
{
    public const MAX_RESULTS = 50;

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

        $sql = "SELECT p.id, p.title, p.slug, p.page_type, p.status, p.published_at, p.created_at, p.created_by,
                       p.seo_json, p.settings_json, u.name AS author_name
                FROM studiobuilder_pages p
                LEFT JOIN users u ON u.id = p.created_by
                WHERE p.tenant_id = ?
                  AND p.status = 'published'
                  AND p.page_type IN ('page', 'landing')";
        
        $sqlParams = [$tenantId];

        if ($authorId > 0) {
            $sql .= ' AND p.created_by = ?';
            $sqlParams[] = $authorId;
        }

        if ($search !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.slug LIKE ?)';
            $sqlParams[] = '%' . $search . '%';
            $sqlParams[] = '%' . $search . '%';
        }

        $sql .= " ORDER BY p.{$orderBy} {$order}";

        $pdo = Database::get();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($sqlParams);
        $rawRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rawRows as $row) {
            $seo = [];
            if (!empty($row['seo_json'])) {
                try {
                    $decoded = CanonicalJson::decode((string) $row['seo_json']);
                    if (is_array($decoded)) {
                        $seo = $decoded;
                    }
                } catch (\Throwable) {}
            }

            $settings = [];
            if (!empty($row['settings_json'])) {
                try {
                    $decoded = CanonicalJson::decode((string) $row['settings_json']);
                    if (is_array($decoded)) {
                        $settings = $decoded;
                    }
                } catch (\Throwable) {}
            }

            $postCategory = (string) ($settings['category'] ?? 'General');
            if ($category !== '' && strcasecmp($postCategory, $category) !== 0) {
                continue;
            }

            if (!empty($params['featured_only']) && empty($settings['featured'])) {
                continue;
            }

            $pubDate = !empty($row['published_at']) ? (string) $row['published_at'] : (string) $row['created_at'];
            $timestamp = strtotime($pubDate) ?: time();

            $excerpt = (string) ($seo['description'] ?? ($settings['excerpt'] ?? ''));
            if ($excerpt === '') {
                $excerpt = 'Read the full story: ' . (string) $row['title'];
            }

            $out[] = [
                'id'             => (int) $row['id'],
                'title'          => (string) $row['title'],
                'slug'           => (string) $row['slug'],
                'url'            => '/' . ltrim((string) $row['slug'], '/'),
                'excerpt'        => mb_substr($excerpt, 0, 200, 'UTF-8'),
                'category'       => $postCategory,
                'author_name'    => (string) ($row['author_name'] ?? 'Staff Author'),
                'author_id'      => (int) ($row['created_by'] ?? 1),
                'published_at'   => $pubDate,
                'date_formatted' => date('M j, Y', $timestamp),
                'featured_image' => (string) ($settings['featured_image_url'] ?? ''),
            ];
        }

        return array_slice($out, $offset, $limit);
    }
}
