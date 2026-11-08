<?php
/**
 * Kohevo Studio — `theme.field_value` renderer: one custom field of the page being shown (Types & fields, set on the
 * page's settings screen), written the way its type reads best: an image as an image, an address as a link, a list as a
 * list. The value is already cleaned by `PageMeta::clean`; it is escaped again here and an address is checked again.
 *
 * Presentation: `before` / `after` wrap a value in plain text ("Year 2023", "97/100"); a list, choices or terms can
 * read as `chips` or as a `ticker` the marquee runtime scrolls (data-sb-ticker); an address can show its domain
 * (rtjrentals.com ↗) or a fixed text instead of the whole address; an image takes its alt text from another field,
 * and the value can sit on a background taken from a colour field of the same item (`bg_field`).
 *
 * An image from the media library is drawn like the Image block's: its width and height (no layout shift when it
 * loads), its WebP sizes as a srcset, lazy unless `priority` asks for it first (the page's main picture).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;

final class FieldValueRenderer implements BlockRendererInterface
{
    private const TAGS = ['div', 'p', 'span', 'h2', 'h3', 'h4'];

    public function type(): string
    {
        return 'theme.field_value';
    }

    public function isDynamic(): bool
    {
        return true;
    }

    public function render(BlockRenderScope $scope): string
    {
        $key = $scope->string('field');
        $tag = $scope->string('tag', 'div');
        $tag = in_array($tag, self::TAGS, true) ? $tag : 'div';
        $meta = $scope->context()->attribute('page_meta');
        $meta = is_array($meta) ? $meta : [];

        $field = null;
        foreach (is_array($meta['fields'] ?? null) ? $meta['fields'] : [] as $f) {
            if (is_array($f) && ($f['key'] ?? null) === $key) {
                $field = $f;
                break;
            }
        }
        $values = is_array($meta['values'] ?? null) ? $meta['values'] : [];
        $value = $key !== '' ? ($values[$key] ?? null) : null;
        $body = $field !== null ? self::format($field, $value) : '';
        $type = MetaFieldTypes::normalize((string) ($field['type'] ?? 'text'));
        if ($body !== '' && $field !== null) {
            $body = $this->present($scope, $type, $body, $value, $values);
        }

        if ($body === '') {
            $fallback = $scope->string('fallback');
            if ($fallback !== '') {
                $body = Html::e($fallback);
            } elseif ($scope->showsDiagnostics()) {
                $body = '<em>' . Html::e($key === '' ? 'Choose a field.' : 'No value for "' . $key . '" on this page.') . '</em>';
            } else {
                return '';
            }
        }

        $label = '';
        if ($scope->bool('show_label') && $field !== null && $body !== '') {
            $label = '<span class="sb-field-value__label">' . Html::e((string) ($field['label'] ?? $key)) . '</span> ';
        }
        $postUrl = (string) $scope->context()->attribute('post_url', '');
        if ($scope->bool('link_to_post') && $postUrl !== '') {
            $body = '<a class="sb-field-value__link" href="' . Html::e($postUrl) . '">' . $body . '</a>';
        }
        $fit = in_array($scope->string('fit'), ['cover', 'contain'], true) ? ' sb-field-value--' . $scope->string('fit') : '';
        $before = $scope->string('before');
        $after = $scope->string('after');
        if ($body !== '' && ($before !== '' || $after !== '')) {
            $body = ($before !== '' ? '<span class="sb-field-value__before">' . Html::e($before) . '</span>' : '') . $body
                . ($after !== '' ? '<span class="sb-field-value__after">' . Html::e($after) . '</span>' : '');
        }
        // A background from a colour field of the same item (an industry's own colour on its tag, say).
        $bgKey = $scope->string('bg_field');
        $bg = $bgKey !== '' && is_string($values[$bgKey] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $values[$bgKey]) === 1 ? ' style="background-color:' . $values[$bgKey] . '"' : '';
        return '<' . $tag . ' class="sb-field-value sb-field-value--' . Html::e($type) . $fit . '"' . $bg . '>' . $label . $body . '</' . $tag . '>';
    }

    /**
     * The value as the block asks to show it: list items as chips or a ticker, an address as its domain or a text,
     * an image with the alt text of another field.
     *
     * @param array<string, mixed> $values every field value of the item, for the alt text
     */
    private function present(BlockRenderScope $scope, string $type, string $body, mixed $value, array $values): string
    {
        $lists = ['list', 'multiselect', 'checkboxes', 'terms', 'repeater'];
        $style = $scope->string('list_style', 'list');
        if (in_array($type, $lists, true) && in_array($style, ['chips', 'ticker'], true) && str_starts_with($body, '<ul>')) {
            $speed = is_int($v = $scope->prop('ticker_speed')) && $v >= 1 && $v <= 10 ? $v : 3;
            return '<ul class="sb-field-chips' . ($style === 'ticker' ? ' sb-field-chips--ticker" data-sb-ticker="' . $speed . ' left' : '') . '">' . substr($body, 4);
        }
        if ($type === 'url' && is_string($value)) {
            $mode = $scope->string('url_label', 'full');
            $text = $mode === 'domain'
                ? (string) preg_replace('#^https?://(www\.)?#i', '', rtrim($value, '/'))
                : ($mode === 'text' ? $scope->string('link_text') : '');
            if ($text !== '') {
                return (string) preg_replace('#>[^<]*</a>$#', '>' . Html::e($text) . ($scope->bool('link_arrow') ? ' ↗' : '') . '</a>', $body);
            }
        }
        if (in_array($type, ['image', 'gallery'], true) && $body !== '') {
            $altKey = $scope->string('alt_field');
            $alt = $altKey !== '' && is_scalar($values[$altKey] ?? null) ? trim((string) $values[$altKey]) : '';
            $priority = $scope->bool('priority', false);
            $out = '';
            foreach ($type === 'image' ? [$value] : (is_array($value) ? array_values($value) : []) as $url) {
                if (!is_string($url) || preg_match('~^(https?://[^\s"\'<>]+|/[^/\s"\'<>][^\s"\'<>]*)$~i', $url) !== 1) {
                    continue;
                }
                $first = $priority && $out === '';
                $image = self::libraryImage($scope, $url);
                $out .= $image !== null
                    ? ImageRenderer::img($image->url, $alt !== '' ? $alt : $image->alt, $image->width, $image->height, null, !$first, $image->srcset, $first)
                    : '<img src="' . Html::e($url) . '" alt="' . Html::e($alt) . '"' . ($first ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async">';
            }
            return $out !== '' ? $out : $body;
        }
        return $body;
    }

    /** The library's record of an image field's file (by its path under /uploads/), resolved as the Image block resolves one, or null. */
    private static function libraryImage(BlockRenderScope $scope, string $url): ?ResolvedMedia
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $at = strpos($path, '/uploads/');
        if ($at === false || !function_exists('current_tenant_id') || !class_exists('Database')) {
            return null;
        }
        try {
            $id = \Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ? LIMIT 1', [current_tenant_id(), substr($path, $at)]);
        } catch (\Throwable $ignored) {
            return null;
        }
        return is_numeric($id) && (int) $id > 0 ? $scope->image(['media_id' => (int) $id]) : null;
    }

    /** @param array<string, mixed> $field */
    public static function format(array $field, mixed $value): string
    {
        $type = MetaFieldTypes::normalize((string) ($field['type'] ?? 'text'));
        $choices = [];
        foreach (is_array($field['options']['choices'] ?? null) ? $field['options']['choices'] : [] as $c) {
            $choices[(string) ($c['value'] ?? '')] = (string) ($c['label'] ?? $c['value'] ?? '');
        }
        $e = static fn(mixed $v): string => Html::e(is_scalar($v) ? (string) $v : '');
        $safeUrl = static fn(mixed $v): string => is_string($v) && ((str_starts_with($v, '/') && !str_starts_with($v, '//')) || preg_match('#^https?://[^\s"<>]+$#i', $v) === 1) ? $v : '';
        $list = static fn(array $items, string $tag = 'ul'): string => $items === [] ? '' : "<{$tag}>" . implode('', array_map(static fn(string $i): string => '<li>' . $i . '</li>', $items)) . "</{$tag}>";

        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        switch ($type) {
            case 'boolean':
                return $value ? 'Yes' : 'No';
            case 'url':
                $u = $safeUrl($value);
                return $u === '' ? '' : '<a href="' . $e($u) . '" rel="noopener">' . $e($u) . '</a>';
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . $e($value) . '">' . $e($value) . '</a>' : '';
            case 'phone':
                $tel = preg_replace('/[^0-9+]/', '', (string) $value);
                return $tel === '' ? '' : '<a href="tel:' . $e($tel) . '">' . $e($value) . '</a>';
            case 'image':
                $u = $safeUrl($value);
                return $u === '' ? '' : '<img src="' . $e($u) . '" alt="" loading="lazy">';
            case 'gallery':
                $imgs = array_filter(array_map($safeUrl, is_array($value) ? $value : []));
                return implode('', array_map(static fn(string $u): string => '<img src="' . $e($u) . '" alt="" loading="lazy">', $imgs));
            case 'file':
                $u = $safeUrl($value);
                return $u === '' ? '' : '<a href="' . $e($u) . '" download>' . $e(basename((string) parse_url($u, PHP_URL_PATH))) . '</a>';
            case 'video':
                $u = $safeUrl($value);
                return $u === '' ? '' : '<a href="' . $e($u) . '" rel="noopener">' . $e($u) . '</a>';
            case 'select':
            case 'radio':
                return $e($choices[(string) $value] ?? $value);
            case 'multiselect':
            case 'checkboxes':
            case 'terms':
                return $list(array_map(static fn($v): string => $e($choices[(string) $v] ?? $v), is_array($value) ? $value : []));
            case 'list':
                return $list(array_map($e, is_array($value) ? $value : []));
            case 'keyvalue':
                $rows = '';
                foreach (is_array($value) ? $value : [] as $k => $v) {
                    $rows .= '<dt>' . $e($k) . '</dt><dd>' . $e($v) . '</dd>';
                }
                return $rows === '' ? '' : '<dl>' . $rows . '</dl>';
            case 'daterange':
                return is_array($value) ? $e(($value['start'] ?? '') . ' – ' . ($value['end'] ?? '')) : '';
            case 'color':
                // Re-checked here: the value goes into inline CSS, and a stored value may predate the save-time check.
                if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                    return $e($value);
                }
                return '<span class="sb-field-value__swatch" style="background:' . $value . '"></span> ' . $e($value);
            case 'rating':
                // One star per point: the scale is kept to 1-10 whatever is stored, so a huge max cannot exhaust memory.
                $max = max(1, min(MetaFieldTypes::MAX_RATING, (int) ($field['options']['max'] ?? 5)));
                $n = max(0, min($max, (int) round((float) $value)));
                return '<span role="img" aria-label="' . $n . ' / ' . $max . '">' . str_repeat('★', $n) . str_repeat('☆', max(0, $max - $n)) . '</span>';
            case 'price':
                return $e(number_format((float) $value, ((float) $value) == floor((float) $value) ? 0 : 2));
            case 'textarea':
                return nl2br($e($value), false);
            case 'richtext':
                return RepeaterRenderer::rich($value);
            case 'repeater':
                $subs = MetaFieldTypes::subfields($field);
                $first = (string) ($subs[0]['key'] ?? '');
                return $list(array_map(static fn(mixed $row): string => $e(is_array($row) ? ($row[$first] ?? '') : ''), is_array($value) ? $value : []));
            case 'relation':
            case 'relations':
                return ''; // a link to another page needs its address, not yet resolved here
            default: // text, number, range, date, time, datetime, location
                return $e($value);
        }
    }
}
