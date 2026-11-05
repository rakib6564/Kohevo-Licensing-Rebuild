<?php
/**
 * Kohevo Studio — structured data (schema.org JSON-LD) for search engines, written by Studio instead of pasted code.
 *
 *  - The business, set on Site & SEO (setting `studio_seo_business`): its kind (an organisation, a local business, an
 *    online store, a restaurant...), name, other name, logo, description, founder and profiles elsewhere (sameAs).
 *    Every public page carries it, with the site (WebSite) it publishes.
 *  - An item a plugin keeps itself (a Shop product) may bring its own: `single_item['schema']`, a list of JSON-LD
 *    objects the plugin builds (Product, BreadcrumbList). They are added to the same graph.
 *
 * Public and preview pages only; the editor canvas carries none. Without a site address (APP_URL) nothing is written,
 * as every node needs an absolute address.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Seo;

use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class StructuredData
{
    public const SETTING = 'studio_seo_business';

    /** schema.org types the screen offers. */
    public const TYPES = [
        'Organization'        => 'Organisation or company',
        'NGO'                 => 'Non-profit organisation',
        'LocalBusiness'       => 'Local business',
        'OnlineStore'         => 'Online store',
        'Store'               => 'Shop with a counter',
        'Restaurant'          => 'Restaurant',
        'FoodEstablishment'   => 'Food business (caterer, bakery...)',
        'ProfessionalService' => 'Professional service',
        'SportsActivityLocation' => 'Gym or sports club',
    ];

    /**
     * The business as saved: only known keys, trimmed and bounded.
     *
     * @return array<string, mixed> type, name, alternate_name, description, logo_media_id, founder, same_as, telephone, email, vat_id, street, postal_code, locality, country
     */
    public static function clean(array $in): array
    {
        $type = is_string($in['type'] ?? null) && isset(self::TYPES[$in['type']]) ? $in['type'] : '';
        $str = static fn(string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $same = $in['same_as'] ?? [];
        if (is_string($same)) {
            $same = preg_split('/\R/', $same) ?: [];
        }
        $links = [];
        foreach ((array) $same as $u) {
            $u = is_string($u) ? trim($u) : '';
            if ($u !== '' && preg_match('#^https://[^\s<>"\']{3,300}$#i', $u) === 1 && filter_var($u, FILTER_VALIDATE_URL)) {
                $links[$u] = $u;
            }
        }
        return [
            'type'           => $type,
            'name'           => $str('name', 160),
            'alternate_name' => $str('alternate_name', 160),
            'description'    => $str('description', 500),
            'logo_media_id'  => max(0, (int) ($in['logo_media_id'] ?? 0)),
            'founder'        => $str('founder', 160),
            'same_as'        => array_slice(array_values($links), 0, 12),
            // How to reach the business: shown to search engines, never on the page itself.
            'telephone'      => preg_replace('/[^0-9+()\\-. ]/', '', $str('telephone', 40)) ?? '',
            'email'          => filter_var($str('email', 160), FILTER_VALIDATE_EMAIL) ?: '',
            'vat_id'         => preg_replace('/[^A-Za-z0-9 .\\-]/', '', $str('vat_id', 40)) ?? '',
            'street'         => $str('street', 160),
            'postal_code'    => $str('postal_code', 20),
            'locality'       => $str('locality', 100),
            'country'        => $str('country', 60),
        ];
    }

    /** @return array{type: string, name: string, alternate_name: string, description: string, logo_media_id: int, founder: string, same_as: list<string>} */
    public static function business(int $tenantId): array
    {
        try {
            $raw = \Database::setting(self::SETTING, $tenantId);
        } catch (\Throwable $e) {
            $raw = null;
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return self::clean(is_array($data) ? $data : []);
    }

    public static function save(int $tenantId, array $business): void
    {
        \Database::setSetting(self::SETTING, (string) json_encode(self::clean($business), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
    }

    /**
     * The graph for a page: the business and the site, then what the shown item brings.
     *
     * @param array<string, mixed> $business clean()'s shape
     * @param list<array<string, mixed>> $itemNodes
     * @return list<array<string, mixed>>
     */
    public static function graph(array $business, string $siteUrl, string $siteName, ?string $logoUrl, array $itemNodes = []): array
    {
        if ($siteUrl === '') {
            return [];
        }
        $siteUrl = rtrim($siteUrl, '/') . '/';
        $nodes = [];
        $name = $business['name'] !== '' ? $business['name'] : $siteName;
        if ($business['type'] !== '' && $name !== '') {
            $org = ['@type' => $business['type'], '@id' => $siteUrl . '#organization', 'name' => $name, 'url' => $siteUrl];
            if ($business['alternate_name'] !== '') $org['alternateName'] = $business['alternate_name'];
            if ($business['description'] !== '') $org['description'] = $business['description'];
            if ($logoUrl !== null && $logoUrl !== '') $org['logo'] = $logoUrl;
            if ($business['founder'] !== '') $org['founder'] = ['@type' => 'Person', 'name' => $business['founder']];
            if ($business['same_as'] !== []) $org['sameAs'] = $business['same_as'];
            if ($business['telephone'] !== '') $org['telephone'] = $business['telephone'];
            if ($business['email'] !== '') $org['email'] = $business['email'];
            if ($business['vat_id'] !== '') $org['vatID'] = $business['vat_id'];
            if ($business['street'] !== '' || $business['locality'] !== '' || $business['postal_code'] !== '' || $business['country'] !== '') {
                $address = ['@type' => 'PostalAddress'];
                if ($business['street'] !== '') $address['streetAddress'] = $business['street'];
                if ($business['postal_code'] !== '') $address['postalCode'] = $business['postal_code'];
                if ($business['locality'] !== '') $address['addressLocality'] = $business['locality'];
                if ($business['country'] !== '') $address['addressCountry'] = $business['country'];
                $org['address'] = $address;
            }
            $nodes[] = $org;
        }
        if ($name !== '') {
            $site = ['@type' => 'WebSite', '@id' => $siteUrl . '#website', 'name' => $name, 'url' => $siteUrl];
            if ($business['type'] !== '') $site['publisher'] = ['@id' => $siteUrl . '#organization'];
            $nodes[] = $site;
        }
        foreach ($itemNodes as $node) {
            if (is_array($node) && is_string($node['@type'] ?? null)) {
                if ($business['type'] !== '' && ($node['@type'] === 'Product') && !isset($node['brand'])) {
                    $node['brand'] = ['@type' => 'Brand', 'name' => $name];
                }
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /** The <script> for a page, or ''. */
    public static function tags(RenderContext $context): string
    {
        if ($context->mode === RenderMode::Editor) {
            return '';
        }
        $site = $context->site;
        $siteUrl = $site->baseUrl !== '' ? $site->absoluteUrl('/') : '';
        if ($siteUrl === '') {
            return '';
        }
        $business = self::business($context->tenantId);
        $logo = null;
        if ($business['logo_media_id'] > 0) {
            try {
                $path = \Database::value('SELECT path FROM media_files WHERE tenant_id = ? AND id = ?', [$context->tenantId, $business['logo_media_id']]);
                if (is_string($path) && $path !== '') {
                    $logo = preg_match('#^https?://#i', $path) === 1 ? $path : $site->absoluteUrl('/' . ltrim($path, '/'));
                }
            } catch (\Throwable $e) {
                $logo = null;
            }
        }
        $item = $context->attribute('single_item');
        $itemNodes = is_array($item) && is_array($item['schema'] ?? null) ? array_values($item['schema']) : [];
        $graph = self::graph($business, $siteUrl, $site->siteName, $logo, $itemNodes);
        if ($graph === []) {
            return '';
        }
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return is_string($json) ? '<script type="application/ld+json">' . $json . '</script>' : '';
    }
}
