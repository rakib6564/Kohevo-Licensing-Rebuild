<?php
/**
 * Kohevo Studio — the site's RSS feed at `/feed/`: the latest items of the content type Reading names as the posts.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\ReadingSettings;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Render\RichTextSanitizer;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Tenancy\TenantContext;

final class StudioFeed
{
    public static function xml(TenantContext $tenants, SiteContext $site): string
    {
        $tenantId = $tenants->id();
        $reading = ReadingSettings::read($tenantId);
        $rows = $reading['posts_type'] === '' ? [] : (new PostsProvider())->execute($tenants, ['source' => 'posts', 'content_type' => $reading['posts_type'], 'limit' => $reading['feed_items']]);

        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = '';
        foreach ($rows as $row) {
            $url = $site->absoluteUrl((string) $row['url']);
            $when = strtotime((string) ($row['published_at'] ?? ''));
            $text = $reading['feed_content'] === 'full' ? self::fullText($tenantId, (int) $row['id']) : '';
            $items .= '<item><title>' . $x((string) $row['title']) . '</title><link>' . $x($url) . '</link><guid isPermaLink="true">' . $x($url) . '</guid>'
                . ($when !== false ? '<pubDate>' . gmdate(DATE_RSS, $when) . '</pubDate>' : '')
                . '<description>' . $x((string) ($row['excerpt'] ?? '')) . '</description>'
                . ($text !== '' ? '<content:encoded>' . $x($text) . '</content:encoded>' : '')
                . '</item>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
            . '<title>' . $x($site->siteName) . '</title><link>' . $x($site->absoluteUrl('/')) . '</link><description>' . $x($site->siteName) . '</description>'
            . '<atom:link href="' . $x($site->absoluteUrl('/feed/')) . '" rel="self" type="application/rss+xml"/>'
            . $items . '</channel></rss>' . "\n";
    }

    /** An item's rich text fields, in field order. */
    private static function fullText(int $tenantId, int $pageId): string
    {
        $meta = PageMeta::read($tenantId, $pageId);
        $html = '';
        foreach ($meta['type'] === '' ? [] : PageMeta::fieldsFor($tenantId, $meta['type']) as $field) {
            $value = $meta['values'][$field['key'] ?? ''] ?? null;
            if (($field['type'] ?? '') === 'richtext' && is_string($value)) {
                $html .= RichTextSanitizer::sanitize($value, true);
            }
        }
        return $html;
    }
}
