<?php
/**
 * Kohevo Studio (studio-builder) — what a Theme Builder template is previewed with.
 *
 * A single, archive, search or 404 template renders with request context on the public site (the archive's term, the
 * search words, the post being shown). The editor has none, so those blocks fall back to placeholders. A preview may
 * hand them a sample instead. Only this fixed set of values is ever passed on: the caller names a kind of sample
 * (an archive type and term, search words, a page), never an attribute key, and every value is cut to a safe length.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Theme;

use Slate\Module\StudioBuilder\Render\SiteContext;

final class TemplatePreviewContext
{
    public const KINDS         = ['single', 'archive', 'search', '404'];
    public const ARCHIVE_TYPES = ['category', 'tag', 'author'];
    public const MAX_TEXT      = 191;

    /** Which preview a template takes, from its `settings.template_type` (or its conventional slug); null for anything else. */
    public static function kind(string $templateType, string $slug = ''): ?string
    {
        $type = $templateType === 'not_found' ? '404' : $templateType;
        if (in_array($type, self::KINDS, true)) {
            return $type;
        }
        foreach (self::KINDS as $kind) {
            if ($slug === $kind || $slug === 'theme-' . $kind) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * The render attributes for a sample.
     *
     * @param array<string, mixed>      $input   what the preview asked for: `archive_type`, `term`, `search_query`
     * @param array<string, mixed>|null $host    the page row a single template is shown for
     * @return array<string, mixed>
     */
    public static function attributes(string $kind, array $input, ?array $host, SiteContext $site): array
    {
        switch ($kind) {
            case 'archive':
                $type = (string) ($input['archive_type'] ?? '');
                $type = in_array($type, self::ARCHIVE_TYPES, true) ? $type : 'category';
                $term = self::text($input['term'] ?? '');
                $term = $term === '' ? 'Sample' : $term;
                return [
                    'archive_type' => $type,
                    'term'         => $term,
                    'category'     => $type === 'category' ? $term : '',
                    'tag'          => $type === 'tag' ? $term : '',
                ];
            case 'search':
                return ['search_query' => self::text($input['search_query'] ?? ''), 'is_search' => true];
            case '404':
                return ['is_404' => true];
            case 'single':
                if ($host === null) {
                    return [];
                }
                $settings = json_decode((string) ($host['settings_json'] ?? ''), true);
                $category = is_array($settings) && is_string($settings['category'] ?? null) ? self::text($settings['category']) : '';
                return [
                    'post_title' => self::text($host['title'] ?? ''),
                    'post_url'   => $site->absoluteUrl('/' . ltrim((string) ($host['slug'] ?? ''), '/')),
                    'category'   => $category,
                ];
        }
        return [];
    }

    private static function text(mixed $value): string
    {
        $text = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '') : '';
        return mb_substr($text, 0, self::MAX_TEXT);
    }
}
