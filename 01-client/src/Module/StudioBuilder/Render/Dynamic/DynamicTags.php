<?php
/**
 * Kohevo Studio — dynamic tags: any text, link, number or image setting of any block can take its value from the item
 * being shown, the archive, or the site instead of what is typed in it.
 *
 * A block keeps its own props and adds `dynamic`, a map of setting path to tag:
 *
 *   "dynamic": { "text": {"tag": "post_title", "before": "Catering in "}, "items.0.url": {"tag": "field", "key": "menu_link"},
 *                "media": {"tag": "featured_image"}, "@background": {"tag": "field", "key": "hero_image"} }
 *
 * The walk in DocumentRenderer calls apply() once per render, so renderers need no knowledge of tags: they read resolved
 * props. A value is treated as untrusted on its way in: text stays text, links pass the URL allowlist, rich text is
 * sanitized by its renderer, and a number that is not one changes nothing. A setting's own value stays the fallback for
 * images, links and numbers.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Dynamic;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FieldValueRenderer;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\RenderContext;

final class DynamicTags
{
    /** The Container's background image: not a prop, so it has its own path. */
    public const BACKGROUND = '@background';
    /** Where a linked Container points. */
    public const LINK = '@link';

    /** Set on a block whose background comes from a tag (never a canonical document key). */
    public const BACKGROUND_KEY = '__sb_dynamic_background';

    public const MAX_TAGS = 40;
    public const MAX_TEXT = 200;

    private const PATH = '/^(@background|@link|[a-z][a-z0-9_]{0,63}(\.[a-z0-9_]{1,64}){0,3})$/';
    private const CONFIG_KEYS = ['tag', 'key', 'format', 'before', 'after', 'fallback'];

    /** PHP date() formats a tag may be set to. */
    public const DATE_FORMATS = ['', 'F j, Y', 'M j, Y', 'j F Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
    public const TIME_FORMATS = ['', 'g:i a', 'H:i'];

    /** tag => [group, label, kinds it can fill, setting] */
    private const TAGS = [
        'post_title'        => ['post', 'Post Title', ['text']],
        'post_excerpt'      => ['post', 'Post Excerpt', ['text']],
        'post_date'         => ['post', 'Post Date', ['text'], 'date'],
        'post_time'         => ['post', 'Post Time', ['text'], 'time'],
        'post_id'           => ['post', 'Post ID', ['text', 'number']],
        'post_terms'        => ['post', 'Post Terms', ['text']],
        'post_type'         => ['post', 'Post Type', ['text']],
        'post_url'          => ['post', 'Post URL', ['url']],
        'featured_image'    => ['post', 'Featured Image', ['image', 'url']],
        'field'             => ['post', 'Post Custom Field', ['text', 'url', 'number', 'image'], 'field'],
        'author_name'       => ['author', 'Author Name', ['text']],
        'archive_title'     => ['archive', 'Archive Title', ['text']],
        'archive_description' => ['archive', 'Archive Description', ['text']],
        'search_query'      => ['archive', 'Search Term', ['text']],
        'site_name'         => ['site', 'Site Name', ['text']],
        'site_url'          => ['site', 'Site URL', ['url']],
        'site_logo'         => ['site', 'Site Logo', ['image', 'url']],
        'current_date'      => ['site', 'Current Date', ['text'], 'date'],
        'current_time'      => ['site', 'Current Time', ['text'], 'time'],
    ];

    private const GROUPS = ['post' => 'Post', 'author' => 'Author', 'archive' => 'Archive', 'site' => 'Site'];

    /** The tag list the Inspector offers. @return list<array<string, mixed>> */
    public static function forEditor(): array
    {
        $out = [];
        foreach (self::TAGS as $tag => $def) {
            $out[] = [
                'tag' => $tag,
                'group' => \__('studio_dyn_group_' . $def[0], self::GROUPS[$def[0]]),
                'label' => \__('studio_dyn_' . $tag, $def[1]),
                'kinds' => $def[2],
                'setting' => $def[3] ?? '',
            ];
        }
        return $out;
    }

    /** @return list<string> problems with a stored `dynamic` map, for the validator: path => message */
    public static function issues(mixed $dynamic): array
    {
        if (!is_array($dynamic) || ($dynamic !== [] && array_is_list($dynamic))) {
            return ['block.dynamic must be a JSON object.'];
        }
        if (count($dynamic) > self::MAX_TAGS) {
            return ['A block can hold at most ' . self::MAX_TAGS . ' dynamic tags.'];
        }
        $issues = [];
        foreach ($dynamic as $path => $config) {
            $path = (string) $path;
            if (preg_match(self::PATH, $path) !== 1) {
                $issues[] = "Invalid dynamic path '" . substr($path, 0, 80) . "'.";
                continue;
            }
            if (!is_array($config) || !is_string($config['tag'] ?? null) || !isset(self::TAGS[$config['tag']])) {
                $issues[] = "Unknown dynamic tag at '{$path}'.";
                continue;
            }
            foreach ($config as $k => $v) {
                if (!in_array($k, self::CONFIG_KEYS, true) || !is_string($v) || mb_strlen($v) > self::MAX_TEXT) {
                    $issues[] = "Invalid dynamic setting '" . substr((string) $k, 0, 40) . "' at '{$path}'.";
                }
            }
            if (($config['tag'] === 'field') && preg_match('/^[a-z0-9_]{1,64}$/', (string) ($config['key'] ?? '')) !== 1) {
                $issues[] = "The custom field at '{$path}' has no field chosen.";
            }
            $format = (string) ($config['format'] ?? '');
            if ($format !== '' && !in_array($format, [...self::DATE_FORMATS, ...self::TIME_FORMATS], true)) {
                $issues[] = "Unknown date format at '{$path}'.";
            }
        }
        return $issues;
    }

    /**
     * The block with every tag's value in place of its setting.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public static function apply(array $block, BlockDefinitionInterface $definition, RenderContext $context, MediaResolverInterface $media): array
    {
        $dynamic = $block['dynamic'] ?? null;
        if (!is_array($dynamic) || $dynamic === []) {
            return $block;
        }
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        foreach ($dynamic as $path => $config) {
            if (!is_array($config) || !isset(self::TAGS[(string) ($config['tag'] ?? '')])) {
                continue;
            }
            $path = (string) $path;
            if ($path === self::BACKGROUND) {
                $url = self::value($config, 'image', $context, $media);
                if ($url !== '' && Html::safeUrl($url) !== null) {
                    $block[self::BACKGROUND_KEY] = $url;
                }
            } elseif ($path === self::LINK) {
                $url = Html::safeUrl(self::value($config, 'url', $context, $media));
                if ($url !== null && ($block['tag'] ?? '') === 'a') {
                    $block['link'] = array_merge(is_array($block['link'] ?? null) ? $block['link'] : [], ['url' => $url]);
                }
            } else {
                $field = self::fieldAt($definition, $path);
                if ($field !== null) {
                    $props = self::put($props, explode('.', $path), $field, $config, $context, $media);
                }
            }
        }
        $block['props'] = $props;
        return $block;
    }

    /**
     * The schema entry a path leads to, or null when the setting cannot take a tag.
     *
     * @return array<string, mixed>|null
     */
    public static function fieldAt(BlockDefinitionInterface $definition, string $path): ?array
    {
        $schema = $definition->schema();
        $field = null;
        foreach (explode('.', $path) as $part) {
            if ($field !== null && ($field['type'] ?? '') === 'link') {
                return in_array($part, ['label', 'href'], true) ? ['key' => $part, 'type' => $part === 'href' ? 'url' : 'string'] : null;
            }
            if ($field !== null && ($field['type'] ?? '') === 'repeater') {
                if (ctype_digit($part)) {
                    $schema = $field['item_schema'];
                    $field = null;
                    continue;
                }
                return null;
            }
            if ($field !== null) {
                if (($field['type'] ?? '') !== 'object') {
                    return null;
                }
                $schema = $field['properties'];
            }
            $field = null;
            foreach ($schema->fields() as $candidate) {
                if ($candidate['key'] === $part) {
                    $field = $candidate;
                }
            }
            if ($field === null) {
                return null;
            }
        }
        return $field !== null && self::takesTags($field) ? $field : null;
    }

    /** @param array<string, mixed> $field */
    public static function takesTags(array $field): bool
    {
        if (isset($field['pattern']) || isset($field['allowed_values']) || (isset($field['format']) && $field['format'] !== '') || ($field['key'] ?? '') === 'highlight') {
            return false;
        }
        return self::kindOf($field) !== '';
    }

    /** @param array<string, mixed> $field */
    private static function kindOf(array $field): string
    {
        return match ($field['type'] ?? '') {
            'string', 'text', 'rich_text' => 'text',
            'url' => 'url',
            'image', 'media_ref', 'media' => 'image',
            'number' => 'number',
            default => '',
        };
    }

    /**
     * @param array<string|int, mixed> $props
     * @param list<string> $parts
     * @param array<string, mixed> $field
     * @param array<string, mixed> $config
     * @return array<string|int, mixed>
     */
    private static function put(array $props, array $parts, array $field, array $config, RenderContext $context, MediaResolverInterface $media): array
    {
        $key = ctype_digit($parts[0]) ? (int) $parts[0] : $parts[0];
        if (count($parts) > 1) {
            if (!is_array($props[$key] ?? null)) {
                return $props; // a row that no longer exists
            }
            $props[$key] = self::put($props[$key], array_slice($parts, 1), $field, $config, $context, $media);
            return $props;
        }

        $kind = self::kindOf($field);
        $value = self::value($config, $kind, $context, $media, ($field['type'] ?? '') === 'rich_text');
        switch ($kind) {
            case 'image':
                if ($value !== '' && Html::safeUrl($value) !== null) {
                    $own = is_array($props[$key] ?? null) ? $props[$key] : [];
                    $props[$key] = ($field['type'] ?? '') === 'image' ? $value : ['media_id' => 0, 'url' => $value, 'alt' => (string) ($own['alt'] ?? $context->attribute('post_title', ''))];
                }
                break;
            case 'url':
                $url = Html::safeUrl($value);
                if ($url !== null) {
                    $props[$key] = $url;
                }
                break;
            case 'number':
                if (is_numeric($value)) {
                    $props[$key] = !empty($field['integer_only']) ? (int) $value : (float) $value;
                }
                break;
            default:
                if ($value === '' && $context->emitsNodeMetadata()) {
                    $value = self::TAGS[(string) $config['tag']][1];
                }
                $props[$key] = $value;
        }
        return $props;
    }

    /**
     * One tag's value with its Before, After and Fallback.
     *
     * @param array<string, mixed> $config
     */
    private static function value(array $config, string $kind, RenderContext $context, MediaResolverInterface $media, bool $rich = false): string
    {
        $value = self::raw($config, $kind, $context, $media, $rich);
        $fallback = (string) ($config['fallback'] ?? '');
        if ($value === '' && $fallback === '') {
            return '';
        }
        if ($value === '') {
            return $rich ? Html::e($fallback) : $fallback;
        }
        if ($kind !== 'text') {
            return $value;
        }
        $before = (string) ($config['before'] ?? '');
        $after = (string) ($config['after'] ?? '');
        return $rich ? Html::e($before) . $value . Html::e($after) : $before . $value . $after;
    }

    /** @param array<string, mixed> $config */
    private static function raw(array $config, string $kind, RenderContext $context, MediaResolverInterface $media, bool $rich): string
    {
        $attr = static fn(string ...$names): string => self::attr($context, $names);
        $format = (string) ($config['format'] ?? '');
        $text = match ((string) $config['tag']) {
            'post_title' => $attr('title', 'post_title', 'page_title'),
            'post_excerpt' => $attr('post_excerpt', 'page_excerpt'),
            'post_date' => self::date($attr('post_timestamp', 'page_timestamp'), $format, 'F j, Y') ?: $attr('post_date', 'page_date'),
            'post_time' => self::date($attr('post_timestamp', 'page_timestamp'), $format, 'g:i a'),
            'post_id' => $attr('post_id', 'meta_page_id', 'page_id'),
            'post_terms' => $attr('category'),
            'post_type' => self::typeLabel($attr('content_type'), $context),
            'post_url' => $attr('post_url', 'page_url'),
            'featured_image' => self::featured($context, $media),
            'author_name' => $attr('author_name'),
            'archive_title' => $attr('term'),
            'archive_description' => $attr('archive_description'),
            'search_query' => $attr('search_query'),
            'site_name' => $context->site->siteName,
            'site_url' => $context->site->absoluteUrl('/'),
            'site_logo' => $context->site->logoUrl,
            'current_date' => (string) date($format !== '' ? $format : 'F j, Y'),
            'current_time' => (string) date($format !== '' ? $format : 'g:i a'),
            'field' => self::field($context, (string) ($config['key'] ?? ''), $kind, $rich),
            default => '',
        };
        return $rich && $config['tag'] !== 'field' ? Html::e($text) : $text;
    }

    /** @param list<string> $names */
    private static function attr(RenderContext $context, array $names): string
    {
        foreach ($names as $name) {
            $value = $context->attribute($name);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }
        return '';
    }

    private static function date(string $timestamp, string $format, string $default): string
    {
        return ctype_digit($timestamp) ? (string) date($format !== '' ? $format : $default, (int) $timestamp) : '';
    }

    private static function typeLabel(string $type, RenderContext $context): string
    {
        if ($type === '') {
            $meta = $context->attribute('page_meta');
            $type = is_array($meta) ? (string) ($meta['type'] ?? '') : '';
        }
        return $type === '' ? '' : (string) (\Slate\Module\StudioBuilder\Meta\PageMeta::postTypes($context->tenantId)[$type]['singular'] ?? $type);
    }

    private static function featured(RenderContext $context, MediaResolverInterface $media): string
    {
        $url = self::attr($context, ['post_image']);
        if ($url !== '') {
            return $url;
        }
        $id = (int) $context->attribute('page_image_media_id', 0);
        return $id > 0 ? (string) $media->resolveImage($id)?->url : '';
    }

    /** A custom field as the setting needs it: a link's address, an image's file, or readable text. */
    private static function field(RenderContext $context, string $key, string $kind, bool $rich): string
    {
        $meta = $context->attribute('page_meta');
        $meta = is_array($meta) ? $meta : [];
        $value = is_array($meta['values'] ?? null) ? ($meta['values'][$key] ?? null) : null;
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        $field = ['key' => $key, 'type' => 'text'];
        foreach (is_array($meta['fields'] ?? null) ? $meta['fields'] : [] as $f) {
            if (is_array($f) && ($f['key'] ?? null) === $key) {
                $field = $f;
                break;
            }
        }
        $type = MetaFieldTypes::normalize((string) ($field['type'] ?? 'text'));
        if ($kind === 'url' || $kind === 'image') {
            return match (true) {
                $type === 'email' && is_string($value) => 'mailto:' . $value,
                $type === 'phone' && is_string($value) => 'tel:' . preg_replace('/[^0-9+]/', '', $value),
                $type === 'gallery' && is_array($value) => (string) (array_values($value)[0] ?? ''),
                default => is_scalar($value) ? (string) $value : '',
            };
        }
        if ($kind === 'number') {
            return is_scalar($value) ? (string) $value : '';
        }
        $html = FieldValueRenderer::format($field, $value);
        if ($rich) {
            return $html;
        }
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '</dd>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
