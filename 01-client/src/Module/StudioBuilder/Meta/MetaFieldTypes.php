<?php
/**
 * Kohevo Studio — the kinds of meta field a post type can have.
 *
 * One catalogue the Types & fields screen reads for its type chooser (grouped), for the options each type takes, and for
 * validation: a stored field never carries a type or an option the catalogue does not know, whatever was posted.
 *
 * Options are a small, closed set per type: `choices` (a list of value/label pairs), `min` / `max` / `step`, `max_length`,
 * `target` (a post type key, for relations) and `extensions` (file types). Everything else is dropped.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

final class MetaFieldTypes
{
    /** group => label */
    public const GROUPS = [
        'text'     => 'Text',
        'number'   => 'Numbers',
        'choice'   => 'Choices',
        'media'    => 'Media & files',
        'datetime' => 'Date & time',
        'relation' => 'Relations',
        'advanced' => 'Advanced',
    ];

    /**
     * key => [group, label, description, options the type takes]
     *
     * @return array<string, array{group: string, label: string, hint: string, options: list<string>}>
     */
    public static function all(): array
    {
        return [
            'text'        => ['group' => 'text', 'label' => 'Text', 'hint' => 'Single-line string', 'options' => ['max_length']],
            'textarea'    => ['group' => 'text', 'label' => 'Textarea', 'hint' => 'Multi-line plain text', 'options' => ['max_length']],
            'richtext'    => ['group' => 'text', 'label' => 'Rich text', 'hint' => 'Formatted text with headings, lists and links', 'options' => []],
            'email'       => ['group' => 'text', 'label' => 'Email', 'hint' => 'An email address', 'options' => []],
            'phone'       => ['group' => 'text', 'label' => 'Phone', 'hint' => 'A telephone number', 'options' => []],
            'url'         => ['group' => 'text', 'label' => 'URL', 'hint' => 'A web address link', 'options' => []],

            'number'      => ['group' => 'number', 'label' => 'Number', 'hint' => 'Integer or decimal', 'options' => ['min', 'max', 'step']],
            'range'       => ['group' => 'number', 'label' => 'Slider', 'hint' => 'A number picked on a slider', 'options' => ['min', 'max', 'step']],
            'price'       => ['group' => 'number', 'label' => 'Price', 'hint' => 'An amount of money', 'options' => ['min', 'max']],
            'rating'      => ['group' => 'number', 'label' => 'Rating', 'hint' => 'Stars, 1 to 5 by default', 'options' => ['max']],

            'select'      => ['group' => 'choice', 'label' => 'Select', 'hint' => 'One choice from a dropdown', 'options' => ['choices']],
            'radio'       => ['group' => 'choice', 'label' => 'Radio buttons', 'hint' => 'One choice shown as buttons', 'options' => ['choices']],
            'multiselect' => ['group' => 'choice', 'label' => 'Multi-select', 'hint' => 'Several choices from a list', 'options' => ['choices']],
            'checkboxes'  => ['group' => 'choice', 'label' => 'Checkboxes', 'hint' => 'Several choices shown as ticks', 'options' => ['choices']],
            'boolean'     => ['group' => 'choice', 'label' => 'Toggle', 'hint' => 'True or false', 'options' => []],

            'image'       => ['group' => 'media', 'label' => 'Image', 'hint' => 'One media-library image', 'options' => []],
            'gallery'     => ['group' => 'media', 'label' => 'Gallery', 'hint' => 'Several images', 'options' => []],
            'file'        => ['group' => 'media', 'label' => 'File', 'hint' => 'A document or download', 'options' => ['extensions']],
            'video'       => ['group' => 'media', 'label' => 'Video', 'hint' => 'A video address or embed', 'options' => []],

            'date'        => ['group' => 'datetime', 'label' => 'Date', 'hint' => 'A calendar date', 'options' => []],
            'time'        => ['group' => 'datetime', 'label' => 'Time', 'hint' => 'A time of day', 'options' => []],
            'datetime'    => ['group' => 'datetime', 'label' => 'Date & time', 'hint' => 'A date and a time', 'options' => []],
            'daterange'   => ['group' => 'datetime', 'label' => 'Date range', 'hint' => 'A start and an end date', 'options' => []],

            'relation'    => ['group' => 'relation', 'label' => 'Relation', 'hint' => 'One linked item', 'options' => ['target']],
            'relations'   => ['group' => 'relation', 'label' => 'Relations', 'hint' => 'Several linked items', 'options' => ['target']],

            'color'       => ['group' => 'advanced', 'label' => 'Colour', 'hint' => 'A colour value', 'options' => []],
            'location'    => ['group' => 'advanced', 'label' => 'Location', 'hint' => 'An address or map point', 'options' => []],
            'list'        => ['group' => 'advanced', 'label' => 'List', 'hint' => 'A repeatable list of short items', 'options' => ['max']],
            'keyvalue'    => ['group' => 'advanced', 'label' => 'Key–value pairs', 'hint' => 'Specifications such as "Budget: 20k"', 'options' => ['max']],
        ];
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /** The type's label for a list, or the raw key for one the catalogue no longer knows. */
    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? $type;
    }

    /** A field type from a request: a known one, or `text`. */
    public static function normalize(string $type): string
    {
        $type = strtolower(trim($type));
        return self::exists($type) ? $type : 'text';
    }

    /**
     * The options a type takes, cleaned: unknown keys dropped, numbers as numbers, choices as value/label pairs.
     *
     * @param array<string, mixed> $raw        e.g. the posted form
     * @param list<string>         $postTypes  valid post type keys, for a relation's target
     * @return array<string, mixed>
     */
    public static function options(string $type, array $raw, array $postTypes = []): array
    {
        $allowed = self::all()[$type]['options'] ?? [];
        $out = [];
        $num = static fn(mixed $v): int|float|null => is_numeric($v) && abs((float) $v) <= 1.0E12 ? ($v + 0) : null;

        if (in_array('choices', $allowed, true)) {
            $choices = self::parseChoices((string) ($raw['choices'] ?? ''));
            if ($choices !== []) {
                $out['choices'] = $choices;
            }
        }
        foreach (['min', 'max', 'step'] as $k) {
            if (in_array($k, $allowed, true) && ($raw[$k] ?? '') !== '' && ($n = $num($raw[$k])) !== null) {
                $out[$k] = $n;
            }
        }
        if (isset($out['step']) && $out['step'] <= 0) {
            unset($out['step']);
        }
        if (isset($out['min'], $out['max']) && $out['min'] > $out['max']) {
            [$out['min'], $out['max']] = [$out['max'], $out['min']];
        }
        if (in_array('max_length', $allowed, true) && ($raw['max_length'] ?? '') !== '' && is_numeric($raw['max_length'])) {
            $len = (int) $raw['max_length'];
            if ($len >= 1 && $len <= 65535) {
                $out['max_length'] = $len;
            }
        }
        if (in_array('target', $allowed, true)) {
            $target = strtolower(trim((string) ($raw['target'] ?? '')));
            if ($target !== '' && in_array($target, $postTypes, true)) {
                $out['target'] = $target;
            }
        }
        if (in_array('extensions', $allowed, true)) {
            $exts = [];
            foreach (preg_split('/[\s,;]+/', strtolower((string) ($raw['extensions'] ?? ''))) ?: [] as $e) {
                $e = ltrim($e, '.');
                if ($e !== '' && preg_match('/^[a-z0-9]{1,8}$/', $e) === 1) {
                    $exts[$e] = true;
                }
            }
            if ($exts !== []) {
                $out['extensions'] = array_slice(array_keys($exts), 0, 20);
            }
        }
        return $out;
    }

    /**
     * "red : Red" lines (or just "red") into value/label pairs; blank lines and repeats dropped, at most 50.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function parseChoices(string $text): array
    {
        $out = [];
        $seen = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$value, $label] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            $value = mb_substr($value, 0, 80);
            $label = mb_substr($label !== '' ? $label : $value, 0, 120);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => $label];
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }

    /** One line saying what a field's options are, for the list ("3 choices", "0–100", "→ portfolio"). */
    public static function summary(array $field): string
    {
        $o = is_array($field['options'] ?? null) ? $field['options'] : [];
        $bits = [];
        if (!empty($o['choices'])) {
            $bits[] = count($o['choices']) . ' choices';
        }
        if (isset($o['min']) || isset($o['max'])) {
            $bits[] = ($o['min'] ?? '…') . '–' . ($o['max'] ?? '…');
        }
        if (isset($o['step'])) {
            $bits[] = 'step ' . $o['step'];
        }
        if (isset($o['max_length'])) {
            $bits[] = 'up to ' . $o['max_length'] . ' characters';
        }
        if (!empty($o['target'])) {
            $bits[] = '→ ' . $o['target'];
        }
        if (!empty($o['extensions'])) {
            $bits[] = implode(', ', $o['extensions']);
        }
        return implode(' · ', $bits);
    }
}
