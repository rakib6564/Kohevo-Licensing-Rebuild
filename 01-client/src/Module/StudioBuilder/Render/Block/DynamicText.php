<?php
/**
 * Kohevo Studio — text and links a block takes from the item being shown instead of its own props: a Heading or a Text
 * set to the post title, excerpt, date, site name or a custom field, and a link to the item or to a field's address.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

use Slate\Module\StudioBuilder\Render\Html;

final class DynamicText
{
    /** Whether a block must be drawn per request, because its text or link comes from the item. @param array<string, mixed> $block */
    public static function isDynamic(array $block): bool
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        return in_array($props['source'] ?? '', ['post_title', 'post_excerpt', 'post_date', 'site_name', 'field'], true)
            || in_array($props['link_source'] ?? '', ['post_url', 'field'], true);
    }

    /** The text to show: the source's, else `$own`. */
    public static function text(BlockRenderScope $scope, string $own): string
    {
        $context = $scope->context();
        $value = match ($scope->string('source', 'static')) {
            'post_title' => (string) $context->attribute('title', $context->attribute('post_title', '')),
            'post_excerpt' => (string) $context->attribute('post_excerpt', ''),
            'post_date' => (string) $context->attribute('post_date', ''),
            'site_name' => $context->site->siteName,
            'field' => self::field($scope, $scope->string('source_field')),
            default => $own,
        };
        return $value !== '' ? $value : $own;
    }

    /** The checked address to link to, or null. */
    public static function url(BlockRenderScope $scope): ?string
    {
        return Html::safeUrl(match ($scope->string('link_source', 'custom')) {
            'post_url' => (string) $scope->context()->attribute('post_url', ''),
            'field' => self::field($scope, $scope->string('link_field')),
            default => $scope->prop('url'),
        });
    }

    private static function field(BlockRenderScope $scope, string $key): string
    {
        $meta = $scope->context()->attribute('page_meta');
        $value = is_array($meta) && is_array($meta['values'] ?? null) ? ($meta['values'][$key] ?? '') : '';
        return is_scalar($value) ? (string) $value : '';
    }
}
