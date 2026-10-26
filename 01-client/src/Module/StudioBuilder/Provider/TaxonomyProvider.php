<?php
/**
 * Kohevo Studio (studio-builder) — `content.taxonomy` Data Provider.
 *
 * Provides taxonomy categories and tags with post counts for archive filters.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Runtime\PublishedPageData;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

final class TaxonomyProvider implements PublicDataProviderInterface
{
    public function key(): string
    {
        return 'content.taxonomy';
    }

    public function parameterSchema(): FieldSchema
    {
        return FieldSchema::define([
            ['key' => 'type', 'type' => 'enum', 'label' => 'Taxonomy type', 'required' => false, 'allowed_values' => ['category', 'tag'], 'default' => 'category'],
            ['key' => 'limit', 'type' => 'number', 'label' => 'Limit', 'required' => false, 'default' => 20, 'min' => 1, 'max' => 50, 'integer_only' => true],
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
        return 50;
    }

    public function execute(TenantContext $tenants, array $params): array
    {
        $tenantId = $tenants->id();
        if ($tenantId <= 0) {
            return [];
        }
        $type = (string) ($params['type'] ?? 'category');
        $limit = max(1, min(50, (int) ($params['limit'] ?? 20)));

        // Count what is LIVE: published public pages (not headers, footers, components or theme templates),
        // by the category or tag they were published with (the row's settings column mirrors the draft).
        $rows = Database::rows(
            "SELECT id, published_revision_id, active_draft_revision_id, settings_json, seo_json
               FROM studiobuilder_pages
              WHERE tenant_id = ? AND status = 'published' AND published_revision_id IS NOT NULL
                AND page_type IN ('page', 'landing') AND slug NOT LIKE 'template-%'",
            [$tenantId]
        );

        $counts = [];
        $pages = 0;
        foreach (PublishedPageData::forRows($tenantId, $rows) as $live) {
            $settings = $live['settings'];
            if (!empty($settings['is_theme_template']) || (is_string($settings['template_type'] ?? null) && $settings['template_type'] !== '')) {
                continue;
            }
            $pages++;
            $val = trim((string) (is_scalar($settings[$type] ?? null) ? $settings[$type] : ''));
            if ($val !== '') {
                $counts[$val] = ($counts[$val] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            $counts = ['General' => $pages];
        }

        arsort($counts, SORT_NUMERIC);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $name), '-'));
            $out[] = [
                'name'  => (string) $name,
                'slug'  => $slug !== '' ? $slug : 'general',
                'count' => (int) $count,
            ];
        }
        return $out;
    }
}
