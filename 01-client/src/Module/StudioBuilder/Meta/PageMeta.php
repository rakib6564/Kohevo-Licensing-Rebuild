<?php
/**
 * Kohevo Studio — a page's content type and the values of that type's meta fields.
 *
 * The types and field definitions are tenant settings (`studio_custom_post_types`, `studio_meta_fields`); this class holds
 * the values a page carries. A site has no types until it creates them: Studio ships none built in. Values are stored per page in the tenant setting
 * `studio_page_meta_<page id>` as `{"type": "<post type>", "values": {"<field key>": value}}`. They are not kept in the
 * page's `settings_json` because every builder save rewrites that column from the document.
 *
 * `clean()` is the only way a value gets in: it keeps what the field's type allows and its options permit, and drops the
 * rest, whatever was posted.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Data\Database;

final class PageMeta
{
    public const SETTING_PREFIX = 'studio_page_meta_';

    /** Page types that carry a content type. */
    public const PAGE_TYPES = ['page', 'landing'];

    /** @return array<string, array<string, mixed>> key => type, the ones this site created */
    public static function postTypes(int $tenantId): array
    {
        $custom = self::json((string) Database::setting('studio_custom_post_types', $tenantId));
        return array_filter($custom, static fn($t): bool => is_array($t) && isset($t['key']));
    }

    /** @return list<array<string, mixed>> every field definition this site saved */
    public static function allFields(int $tenantId): array
    {
        return array_values(array_filter(self::json((string) Database::setting('studio_meta_fields', $tenantId)), 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public static function fieldsFor(int $tenantId, string $postType): array
    {
        return array_values(array_filter(self::allFields($tenantId), static fn(array $f): bool => ($f['post_type'] ?? '') === $postType));
    }

    /** @return array{type: string, values: array<string, mixed>} */
    public static function read(int $tenantId, int $pageId): array
    {
        $data = self::json((string) Database::setting(self::SETTING_PREFIX . $pageId, $tenantId));
        return [
            'type'   => is_string($data['type'] ?? null) ? $data['type'] : '',
            'values' => is_array($data['values'] ?? null) ? $data['values'] : [],
        ];
    }

    /**
     * Save a page's type and the values posted for that type's fields.
     *
     * @param array<string, mixed> $posted the `meta` array of the form
     * @return array{errors: array<string, string>}
     */
    public static function save(int $tenantId, int $pageId, string $postType, array $posted): array
    {
        if ($postType === '') {
            Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode(['type' => '', 'values' => new \stdClass()]), $tenantId);
            PageRoutes::forget($tenantId);
            return ['errors' => []];
        }
        if (!isset(self::postTypes($tenantId)[$postType])) {
            return ['errors' => ['_type' => 'Choose a content type that exists.']];
        }
        $values = [];
        $errors = [];
        foreach (self::fieldsFor($tenantId, $postType) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $r = self::clean($field, $posted[$key] ?? null);
            if ($r['error'] !== null) {
                $errors[$key] = $r['error'];
                continue;
            }
            $values[$key] = $r['value'];
        }
        if ($errors === []) {
            Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode(['type' => $postType, 'values' => (object) $values], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
            PageRoutes::forget($tenantId);
        }
        return ['errors' => $errors];
    }

    /** A duplicated page starts with the same content type and values. */
    public static function copy(int $tenantId, int $fromPageId, int $toPageId): void
    {
        $raw = Database::setting(self::SETTING_PREFIX . $fromPageId, $tenantId);
        if (is_string($raw) && $raw !== '') {
            Database::setSetting(self::SETTING_PREFIX . $toPageId, $raw, $tenantId);
        }
    }

    /** Drop a page's values when the page is deleted. */
    public static function forget(int $tenantId, int $pageId): void
    {
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tenantId, self::SETTING_PREFIX . $pageId]);
    }

    /** Whether a type has its own entry in the admin sidebar (on unless the site turned it off). */
    public static function showsInMenu(array $type): bool
    {
        return ($type['show_in_menu'] ?? true) !== false;
    }

    /**
     * Give a new page its content type, with no values yet: its fields are filled on its Fields screen, so a required
     * field does not stop the item from being created.
     */
    public static function assignType(int $tenantId, int $pageId, string $postType): bool
    {
        if (!isset(self::postTypes($tenantId)[$postType])) {
            return false;
        }
        Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode(['type' => $postType, 'values' => new \stdClass()]), $tenantId);
        return true;
    }

    /** @return array<int, string> page id => content type key, for every page that has one (one query) */
    public static function typesByPage(int $tenantId): array
    {
        $rows = Database::all('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tenantId, self::SETTING_PREFIX . '%']);
        $types = [];
        foreach ($rows as $row) {
            $type = self::json((string) $row['setting_value'])['type'] ?? '';
            if (is_string($type) && $type !== '') {
                $types[(int) substr((string) $row['setting_key'], strlen(self::SETTING_PREFIX))] = $type;
            }
        }
        return $types;
    }

    /** @return list<int> ids of the pages whose content type is `$postType` */
    public static function pageIdsOfType(int $tenantId, string $postType): array
    {
        $rows = Database::all('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tenantId, self::SETTING_PREFIX . '%']);
        $ids = [];
        foreach ($rows as $row) {
            $data = self::json((string) $row['setting_value']);
            if (($data['type'] ?? '') === $postType) {
                $ids[] = (int) substr((string) $row['setting_key'], strlen(self::SETTING_PREFIX));
            }
        }
        return $ids;
    }

    /**
     * One posted value cleaned for one field.
     *
     * @param array<string, mixed> $field
     * @return array{value: mixed, error: ?string}
     */
    public static function clean(array $field, mixed $raw): array
    {
        $type = MetaFieldTypes::normalize((string) ($field['type'] ?? 'text'));
        $opt = is_array($field['options'] ?? null) ? $field['options'] : [];
        $label = (string) ($field['label'] ?? $field['key'] ?? 'Field');
        $ok = static fn(mixed $v): array => ['value' => $v, 'error' => null];
        $bad = static fn(string $m): array => ['value' => null, 'error' => $m];
        $str = static fn(mixed $v, int $max = 2000): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
        $list = static fn(mixed $v): array => is_array($v) ? array_values(array_filter(array_map(static fn($x): string => is_scalar($x) ? trim((string) $x) : '', $v), static fn(string $x): bool => $x !== '')) : [];
        $lines = static fn(mixed $v): array => array_values(array_filter(array_map('trim', preg_split('/\R/', is_scalar($v) ? (string) $v : '') ?: []), static fn(string $x): bool => $x !== ''));
        $choices = array_map(static fn($c): string => (string) ($c['value'] ?? ''), is_array($opt['choices'] ?? null) ? $opt['choices'] : []);

        $empty = match (true) {
            $type === 'boolean' => false,
            default => $raw === null || $raw === '' || $raw === [],
        };
        if ($empty && $type !== 'boolean') {
            return !empty($field['required']) ? $bad("{$label} is required.") : $ok(in_array($type, ['multiselect', 'checkboxes', 'gallery', 'list', 'relations'], true) ? [] : ($type === 'keyvalue' ? new \stdClass() : ''));
        }

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'richtext':
            case 'location':
                $v = $str($type === 'richtext' ? strip_tags((string) (is_scalar($raw) ? $raw : '')) : $raw, (int) ($opt['max_length'] ?? ($type === 'text' || $type === 'location' ? 255 : 20000)));
                return $v === '' && !empty($field['required']) ? $bad("{$label} is required.") : $ok($v);
            case 'email':
                $v = $str($raw, 254);
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? $ok($v) : $bad("{$label} must be an email address.");
            case 'phone':
                $v = $str($raw, 40);
                return preg_match('/^[0-9+()\-.\s]{3,40}$/', $v) === 1 ? $ok($v) : $bad("{$label} must be a phone number.");
            case 'url':
            case 'video':
            case 'image':
            case 'file':
                $v = $str($raw, 2000);
                $valid = (str_starts_with($v, '/') && !str_starts_with($v, '//')) || (filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v) === 1);
                return $valid ? $ok($v) : $bad("{$label} must be a web address (https://…) or a path starting with /.");
            case 'gallery':
                $urls = $lines($raw);
                foreach ($urls as $u) {
                    if (!((str_starts_with($u, '/') && !str_starts_with($u, '//')) || preg_match('#^https?://\S+$#i', $u) === 1)) {
                        return $bad("{$label} must be one web address per line.");
                    }
                }
                return $ok(array_slice($urls, 0, 50));
            case 'number':
            case 'range':
            case 'price':
            case 'rating':
                if (!is_numeric($raw)) {
                    return $bad("{$label} must be a number.");
                }
                $n = $raw + 0;
                $min = $opt['min'] ?? ($type === 'rating' ? 0 : null);
                $max = $opt['max'] ?? ($type === 'rating' ? 5 : null);
                if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
                    return $bad("{$label} must be " . ($min !== null && $max !== null ? "between {$min} and {$max}." : ($min !== null ? "at least {$min}." : "at most {$max}.")));
                }
                return $ok($n);
            case 'select':
            case 'radio':
                $v = $str($raw, 200);
                return in_array($v, $choices, true) ? $ok($v) : $bad("{$label}: choose one of the listed options.");
            case 'multiselect':
            case 'checkboxes':
                $v = $list($raw);
                return array_diff($v, $choices) === [] ? $ok($v) : $bad("{$label}: choose from the listed options.");
            case 'boolean':
                return $ok($raw === '1' || $raw === 1 || $raw === true || $raw === 'on');
            case 'date':
                $v = $str($raw, 10);
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                return $d && $d->format('Y-m-d') === $v ? $ok($v) : $bad("{$label} must be a date.");
            case 'time':
                $v = $str($raw, 5);
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $ok($v) : $bad("{$label} must be a time.");
            case 'datetime':
                $v = $str($raw, 16);
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v);
                return $d && $d->format('Y-m-d\TH:i') === $v ? $ok($v) : $bad("{$label} must be a date and time.");
            case 'daterange':
                $a = is_array($raw) ? $str($raw['start'] ?? '', 10) : '';
                $b = is_array($raw) ? $str($raw['end'] ?? '', 10) : '';
                if ($a === '' && $b === '') {
                    return !empty($field['required']) ? $bad("{$label} is required.") : $ok('');
                }
                foreach ([$a, $b] as $v) {
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v) {
                        return $bad("{$label} needs a start and an end date.");
                    }
                }
                return $a <= $b ? $ok(['start' => $a, 'end' => $b]) : $bad("{$label}: the end date is before the start.");
            case 'relation':
                return preg_match('/^\d{1,12}$/', $str($raw, 12)) === 1 ? $ok((int) $str($raw, 12)) : $bad("{$label}: choose an item.");
            case 'relations':
                $ids = array_values(array_unique(array_map('intval', array_filter($list($raw), static fn(string $x): bool => preg_match('/^\d{1,12}$/', $x) === 1))));
                return $ok(array_slice($ids, 0, 50));
            case 'color':
                $v = strtolower($str($raw, 7));
                return preg_match('/^#[0-9a-f]{6}$/', $v) === 1 ? $ok($v) : $bad("{$label} must be a colour like #1a2b3c.");
            case 'list':
                return $ok(array_map(static fn(string $x): string => mb_substr($x, 0, 300), array_slice($lines($raw), 0, 100)));
            case 'keyvalue':
                $map = [];
                foreach (array_slice($lines($raw), 0, 100) as $line) {
                    [$k, $val] = array_pad(explode(':', $line, 2), 2, '');
                    $k = mb_substr(trim($k), 0, 100);
                    if ($k !== '') {
                        $map[$k] = mb_substr(trim($val), 0, 500);
                    }
                }
                return $ok((object) $map);
        }
        return $ok($str($raw));
    }

    /** @return array<string, mixed> */
    private static function json(string $raw): array
    {
        $d = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($d) ? $d : [];
    }
}
