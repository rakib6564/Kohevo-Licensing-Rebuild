<?php
/**
 * Kohevo Studio — a page's content type and the values of that type's meta fields.
 *
 * The types and field definitions are tenant settings (`studio_custom_post_types`, `studio_meta_fields`); this class holds
 * the values a page carries. A site has no types until it creates them: Studio ships none built in. Values are stored per page in the tenant setting
 * `studio_page_meta_<page id>` as `{"type": "<post type>", "values": {"<field key>": value}, "template": "<uuid>"}`. They
 * are not kept in the page's `settings_json` because every builder save rewrites that column from the document.
 * `template` is the Single template the item was pinned to on its screen, by uuid because a backup keeps uuids and not
 * ids; absent means the display conditions choose.
 *
 * `clean()` is the only way a value gets in: it keeps what the field's type allows and its options permit, and drops the
 * rest, whatever was posted.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Module\StudioBuilder\Render\RichTextSanitizer;
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
        return array_map(PostTypeSettings::normalize(...), array_filter($custom, static fn($t): bool => is_array($t) && isset($t['key'])));
    }

    /**
     * Save the site's post types, in the stored shape.
     *
     * @param array<string, array<string, mixed>> $types
     */
    public static function savePostTypes(int $tenantId, array $types): void
    {
        Database::setSetting('studio_custom_post_types', json_encode($types, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
        PageRoutes::forget($tenantId);
    }

    /** @param list<array<string, mixed>> $fields every field definition of the site */
    public static function saveFields(int $tenantId, array $fields): void
    {
        Database::setSetting('studio_meta_fields', json_encode(array_values($fields), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
    }

    /**
     * Replace one type's fields, keeping the others'. A field whose key changed takes its saved values along, so renaming
     * a key on the screen loses nothing.
     *
     * @param list<array<string, mixed>> $fields the type's fields, in order
     */
    public static function replaceFieldsFor(int $tenantId, string $postType, array $fields): void
    {
        $before = self::fieldsFor($tenantId, $postType);
        $others = array_filter(self::allFields($tenantId), static fn(array $f): bool => ($f['post_type'] ?? '') !== $postType);
        self::saveFields($tenantId, [...$others, ...$fields]);

        $oldKeys = array_column($before, 'key', 'id');
        $renames = [];
        foreach ($fields as $f) {
            $old = $oldKeys[$f['id'] ?? ''] ?? null;
            if ($old !== null && $old !== $f['key']) {
                $renames[(string) $old] = (string) $f['key'];
            }
        }
        if ($renames !== []) {
            foreach (self::pageIdsOfType($tenantId, $postType) as $pageId) {
                $data = self::json((string) Database::setting(self::SETTING_PREFIX . $pageId, $tenantId));
                $values = is_array($data['values'] ?? null) ? $data['values'] : [];
                $moved = [];
                foreach ($values as $k => $v) {
                    $moved[$renames[$k] ?? $k] = $v;
                }
                $data['values'] = (object) $moved;
                Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
            }
        }
    }

    /**
     * Delete metadata: every item of the type keeps its type and its template, and loses its field values.
     *
     * @return int how many items were cleared
     */
    public static function clearValues(int $tenantId, string $postType): int
    {
        $ids = self::pageIdsOfType($tenantId, $postType);
        foreach ($ids as $pageId) {
            $data = self::json((string) Database::setting(self::SETTING_PREFIX . $pageId, $tenantId));
            $data['values'] = new \stdClass();
            Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
        }
        return count($ids);
    }

    /**
     * The saved values of every item of a type, for the admin list's columns and filters (one query).
     *
     * @return array<int, array<string, mixed>> page id => field values
     */
    public static function valuesByPage(int $tenantId, string $postType): array
    {
        $rows = Database::all('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tenantId, self::SETTING_PREFIX . '%']);
        $out = [];
        foreach ($rows as $row) {
            $data = self::json((string) $row['setting_value']);
            if (($data['type'] ?? '') === $postType) {
                $out[(int) substr((string) $row['setting_key'], strlen(self::SETTING_PREFIX))] = is_array($data['values'] ?? null) ? $data['values'] : [];
            }
        }
        return $out;
    }

    /**
     * The pages Query Loops must leave out: items of a type that is not public, and when searching, of one excluded from
     * search. Nothing is read beyond the types when every type is listed, which is the usual case.
     *
     * @return list<int>
     */
    public static function unlistedPageIds(int $tenantId, bool $search): array
    {
        $hidden = array_keys(array_filter(self::postTypes($tenantId), static fn(array $t): bool => !PostTypeSettings::isListed($t, $search)));
        $ids = [];
        foreach ($hidden as $key) {
            array_push($ids, ...self::pageIdsOfType($tenantId, (string) $key));
        }
        return $ids;
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

    /** @return array{type: string, values: array<string, mixed>, template: string} */
    public static function read(int $tenantId, int $pageId): array
    {
        $data = self::json((string) Database::setting(self::SETTING_PREFIX . $pageId, $tenantId));
        return [
            'type'   => is_string($data['type'] ?? null) ? $data['type'] : '',
            'values' => is_array($data['values'] ?? null) ? $data['values'] : [],
            'template' => is_string($data['template'] ?? null) ? $data['template'] : '',
        ];
    }

    /** Pin an item to one Single template, or give the choice back to the display conditions with ''. */
    public static function setTemplate(int $tenantId, int $pageId, string $templateUuid): void
    {
        $data = self::json((string) Database::setting(self::SETTING_PREFIX . $pageId, $tenantId));
        $data['type'] ??= '';
        $data['values'] = (object) (is_array($data['values'] ?? null) ? $data['values'] : []);
        if ($templateUuid === '') {
            unset($data['template']);
        } else {
            $data['template'] = $templateUuid;
        }
        Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
    }

    /**
     * Save a page's type and the values posted for that type's fields.
     *
     * @param array<string, mixed> $posted the `meta` array of the form
     * @return array{errors: array<string, string>}
     */
    public static function save(int $tenantId, int $pageId, string $postType, array $posted): array
    {
        $pin = self::read($tenantId, $pageId)['template'];
        $keep = $pin !== '' ? ['template' => $pin] : [];
        if ($postType === '') {
            Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode(['type' => '', 'values' => new \stdClass()] + $keep), $tenantId);
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
            Database::setSetting(self::SETTING_PREFIX . $pageId, json_encode(['type' => $postType, 'values' => (object) $values] + $keep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
            PageRoutes::forget($tenantId);
        }
        return ['errors' => $errors];
    }

    /**
     * The content type a Single template lays out, from its conditions, or ''.
     *
     * @param array<string, mixed> $document
     */
    public static function singleTemplateType(array $document): string
    {
        $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        if (($settings['template_type'] ?? '') !== 'single') {
            return '';
        }
        foreach (is_array($settings['conditions']['rules'] ?? null) ? $settings['conditions']['rules'] : [] as $rule) {
            if (is_array($rule) && ($rule['action'] ?? '') === 'include' && ($rule['condition'] ?? '') === 'singular' && is_string($rule['value'] ?? null)) {
                return $rule['value'];
            }
        }
        return '';
    }

    /** @param array<string, mixed> $editorState what `loadEditorDocument` answers */
    public static function singleTemplateTypeOf(array $editorState): string
    {
        return self::singleTemplateType(is_array($editorState['document'] ?? null) ? $editorState['document'] : []);
    }

    /**
     * The item a template is previewed with: the one asked for when it is of that type, else the newest published one.
     *
     * @return ?array{id: int, title: string}
     */
    public static function previewItem(int $tenantId, string $postType, int $wanted = 0): ?array
    {
        $items = self::previewItems($tenantId, $postType);
        foreach ($items as $item) {
            if ($item['id'] === $wanted) {
                return $item;
            }
        }
        return $items[0] ?? null;
    }

    /** @return list<array{id: int, title: string}> a type's items, newest first */
    public static function previewItems(int $tenantId, string $postType): array
    {
        $ids = self::pageIdsOfType($tenantId, $postType);
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all("SELECT id, title FROM studiobuilder_pages WHERE tenant_id = ? AND status <> 'archived' AND id IN ({$marks}) ORDER BY published_at IS NULL, published_at DESC, id DESC LIMIT 100", array_merge([$tenantId], $ids));
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $rows);
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
        return PostTypeSettings::inSidebar($type);
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
            return !empty($field['required']) ? $bad("{$label} is required.") : $ok(in_array($type, ['multiselect', 'checkboxes', 'gallery', 'list', 'relations', 'repeater'], true) ? [] : ($type === 'keyvalue' ? new \stdClass() : ''));
        }

        switch ($type) {
            case 'richtext':
                $v = trim(RichTextSanitizer::sanitize(is_scalar($raw) ? (string) $raw : '', true));
                if (mb_strlen($v) > 500000) {
                    return $bad("{$label} is too long.");
                }
                return trim(strip_tags($v)) === '' && !empty($field['required']) ? $bad("{$label} is required.") : $ok($v);
            case 'repeater':
                $rows = [];
                foreach (array_slice(is_array($raw) ? array_values($raw) : [], 0, 100) as $i => $posted) {
                    $row = [];
                    $filled = false;
                    foreach (MetaFieldTypes::subfields($field) as $sub) {
                        $r = self::clean($sub, is_array($posted) ? ($posted[$sub['key']] ?? null) : null);
                        if ($r['error'] !== null) {
                            return $bad('Row ' . ($i + 1) . ': ' . $r['error']);
                        }
                        $row[$sub['key']] = $r['value'];
                        $filled = $filled || ($r['value'] !== '' && trim(strip_tags((string) $r['value'])) !== '');
                    }
                    if ($filled) {
                        $rows[] = $row;
                    }
                }
                return $rows === [] && !empty($field['required']) ? $bad("{$label} is required.") : $ok($rows);
            case 'text':
            case 'textarea':
            case 'location':
                $v = $str($raw, (int) ($opt['max_length'] ?? ($type === 'text' || $type === 'location' ? 255 : 20000)));
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
