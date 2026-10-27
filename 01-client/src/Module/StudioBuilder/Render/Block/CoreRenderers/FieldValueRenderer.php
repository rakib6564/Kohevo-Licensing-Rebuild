<?php
/**
 * Kohevo Studio — `theme.field_value` renderer: one custom field of the page being shown (Types & fields, set on the
 * page's settings screen), written the way its type reads best: an image as an image, an address as a link, a list as a
 * list. The value is already cleaned by `PageMeta::clean`; it is escaped again here and an address is checked again.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

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
        $value = is_array($meta['values'] ?? null) && $key !== '' ? ($meta['values'][$key] ?? null) : null;
        $body = $field !== null ? self::format($field, $value) : '';

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
        return '<' . $tag . ' class="sb-field-value sb-field-value--' . Html::e(MetaFieldTypes::normalize((string) ($field['type'] ?? 'text'))) . '">' . $label . $body . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $field */
    private static function format(array $field, mixed $value): string
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
                return '<span aria-label="' . $n . ' / ' . $max . '">' . str_repeat('★', $n) . str_repeat('☆', max(0, $max - $n)) . '</span>';
            case 'price':
                return $e(number_format((float) $value, ((float) $value) == floor((float) $value) ? 0 : 2));
            case 'textarea':
            case 'richtext':
                return nl2br($e($value), false);
            case 'relation':
            case 'relations':
                return ''; // a link to another page needs its address, not yet resolved here
            default: // text, number, range, date, time, datetime, location
                return $e($value);
        }
    }
}
