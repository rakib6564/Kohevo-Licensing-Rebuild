<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8A JSON package format ("1.0").
 *
 * The strict, versioned envelope a Studio page (and what it references) is
 * exported as and imported from. Pure: no database, no tenant, no I/O. It
 * only answers "is this envelope well formed?" — every canonical document
 * inside it is still validated by DocumentValidator on import, and every
 * reference is resolved by the (tenant-scoped) import planner.
 *
 *   {
 *     "package_format":  "kohevo-studio-package",
 *     "package_version": "1.0",
 *     "exported_at":     "2026-09-30T12:00:00Z",      (informational only)
 *     "items": [ {kind: page | global_component | template | tokens, key, ..., content_hash} ]
 *   }
 *
 * Item shapes (every key required, no other key allowed):
 *   page              kind key title slug page_type route_mode document media content_hash
 *   global_component  kind key source_ref title slug document media content_hash
 *   template          kind key template_key template_type category name description document media content_hash
 *   tokens            kind key token_group tokens content_hash
 * Media descriptor:   {key, path, mime}
 *
 * Identity rules: `key` is package-local. `source_ref` (a component's uuid in
 * the SOURCE site) and media descriptor keys are package-local too — never a
 * target identity. Database ids, revision ids, user ids and tenant ids are
 * not part of the format; `tenant_id` anywhere is rejected.
 *
 * `content_hash` is a consistency / tamper-DETECTION check (sha256 over the
 * item's stable encoding), never a signature and never a reason to trust the
 * content: a matching hash only means the item was not edited after export.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Domain\PageAddress;

final class StudioPackageFormat
{
    public const FORMAT  = 'kohevo-studio-package';
    public const VERSION = '1.0';

    public const MAX_ITEMS             = 25;
    public const MAX_MEDIA_PER_ITEM    = 250;
    public const MAX_TOKENS_PER_ITEM   = 64;

    public const KIND_PAGE      = 'page';
    public const KIND_COMPONENT = 'global_component';
    public const KIND_TEMPLATE  = 'template';
    public const KIND_TOKENS    = 'tokens';

    public const TOP_LEVEL_KEYS = ['package_format', 'package_version', 'exported_at', 'items'];

    /** kind => exact key set */
    public const ITEM_KEYS = [
        self::KIND_PAGE      => ['kind', 'key', 'title', 'slug', 'page_type', 'route_mode', 'document', 'media', 'content_hash'],
        self::KIND_COMPONENT => ['kind', 'key', 'source_ref', 'title', 'slug', 'document', 'media', 'content_hash'],
        self::KIND_TEMPLATE  => ['kind', 'key', 'template_key', 'template_type', 'category', 'name', 'description', 'document', 'media', 'content_hash'],
        self::KIND_TOKENS    => ['kind', 'key', 'token_group', 'tokens', 'content_hash'],
    ];

    public const MEDIA_KEYS = ['key', 'path', 'mime'];

    /** Page items carry the addressable Studio document types; components have their own kind. */
    public const PAGE_ITEM_TYPES = ['page', 'landing', 'system', 'header_partial', 'footer_partial'];

    public const ITEM_KEY_PATTERN    = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    public const EXPORTED_AT_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/';
    public const HASH_PATTERN        = '/^[0-9a-f]{64}$/';

    /**
     * Validate the envelope and every item's SHAPE. Returns issues
     * (`[severity, code, item, path, message]`); an empty list means the
     * package is structurally usable — nothing about its content is trusted yet.
     *
     * @param mixed $package
     * @return list<array{severity: string, code: string, item: ?string, path: string, message: string}>
     */
    public static function validate(mixed $package): array
    {
        $issues = [];
        $err = static function (string $code, ?string $item, string $path, string $message) use (&$issues): void {
            $issues[] = ['severity' => 'error', 'code' => $code, 'item' => $item, 'path' => $path, 'message' => $message];
        };

        if (!is_array($package) || $package === [] || array_is_list($package)) {
            $err('invalid_package', null, '$', 'The package must be a JSON object.');
            return $issues;
        }
        foreach (self::forbiddenTenantPaths($package, '$') as $path) {
            $err('forbidden_tenant_id', null, $path, 'A package must never carry tenant_id; the target tenant comes from your session only.');
        }
        foreach (array_keys($package) as $k) {
            if (!in_array((string) $k, self::TOP_LEVEL_KEYS, true)) {
                $err('unknown_package_key', null, '$.' . self::pathKey((string) $k), 'Unknown top-level package key.');
            }
        }
        if (($package['package_format'] ?? null) !== self::FORMAT) {
            $err('invalid_package', null, '$.package_format', 'package_format must be "' . self::FORMAT . '".');
        }
        if (($package['package_version'] ?? null) !== self::VERSION) {
            $err('invalid_package', null, '$.package_version', 'Unsupported package_version (expected "' . self::VERSION . '").');
        }
        $exportedAt = $package['exported_at'] ?? null;
        if (!is_string($exportedAt) || preg_match(self::EXPORTED_AT_PATTERN, $exportedAt) !== 1) {
            $err('invalid_package', null, '$.exported_at', 'exported_at must be a UTC timestamp like 2026-01-31T12:00:00Z.');
        }
        $items = $package['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            $err('invalid_package', null, '$.items', 'items must be a non-empty list.');
            return $issues;
        }
        if (count($items) > self::MAX_ITEMS) {
            $err('invalid_package', null, '$.items', 'A package may hold at most ' . self::MAX_ITEMS . ' items.');
            return $issues;
        }

        $seenKeys = [];
        foreach ($items as $i => $item) {
            $path = "\$.items[{$i}]";
            if (!is_array($item) || $item === [] || array_is_list($item)) {
                $err('invalid_package', null, $path, 'Each item must be a JSON object.');
                continue;
            }
            $kind = $item['kind'] ?? null;
            $key  = is_string($item['key'] ?? null) ? $item['key'] : null;
            $ref  = ($key !== null && preg_match(self::ITEM_KEY_PATTERN, $key) === 1) ? $key : null;
            if ($ref === null) {
                $err('invalid_package', null, "{$path}.key", 'Each item needs a key: a short lowercase slug unique within the package.');
            } elseif (isset($seenKeys[$ref])) {
                $err('invalid_package', $ref, "{$path}.key", 'Item keys must be unique within the package.');
            } else {
                $seenKeys[$ref] = true;
            }
            if (!is_string($kind) || !isset(self::ITEM_KEYS[$kind])) {
                $err('unknown_item_type', $ref, "{$path}.kind", 'Unknown item kind (expected page, global_component, template or tokens).');
                continue;
            }
            foreach (array_keys($item) as $k) {
                if (!in_array((string) $k, self::ITEM_KEYS[$kind], true)) {
                    $err('invalid_package', $ref, "{$path}." . self::pathKey((string) $k), "Unexpected key in a {$kind} item.");
                }
            }
            foreach (self::ITEM_KEYS[$kind] as $required) {
                if (!array_key_exists($required, $item)) {
                    $err('invalid_package', $ref, "{$path}.{$required}", "A {$kind} item requires '{$required}'.");
                }
            }
            foreach (self::itemShapeIssues($kind, $item, $path) as [$code, $p, $message]) {
                $err($code, $ref, $p, $message);
            }
            $hash = $item['content_hash'] ?? null;
            if (!is_string($hash) || preg_match(self::HASH_PATTERN, $hash) !== 1) {
                $err('invalid_package', $ref, "{$path}.content_hash", 'content_hash must be a sha256 hex digest.');
            } elseif (!hash_equals(self::itemHash($item), $hash)) {
                $err('invalid_package', $ref, "{$path}.content_hash", 'The item content does not match its content_hash (the package was modified after export).');
            }
        }
        return $issues;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function itemShapeIssues(string $kind, array $item, string $path): array
    {
        $out = [];
        $bad = static function (string $field, string $message) use (&$out, $path): void {
            $out[] = ['invalid_package', "{$path}.{$field}", $message];
        };
        $plain = static fn(mixed $v, int $max): bool => is_string($v) && trim($v) !== '' && mb_strlen($v, 'UTF-8') <= $max && preg_match('/[<>\x00-\x1F\x7F]/', $v) !== 1;

        if (in_array($kind, [self::KIND_PAGE, self::KIND_COMPONENT], true)) {
            if (array_key_exists('title', $item) && !$plain($item['title'], 255)) {
                $bad('title', 'title must be plain text of 1..255 characters.');
            }
            if (array_key_exists('slug', $item) && (!is_string($item['slug']) || preg_match(PageAddress::SLUG_PATTERN, $item['slug']) !== 1)) {
                $bad('slug', 'slug must be lowercase letters, digits and single hyphens.');
            }
        }
        if ($kind === self::KIND_PAGE) {
            if (array_key_exists('page_type', $item) && !in_array($item['page_type'], self::PAGE_ITEM_TYPES, true)) {
                $bad('page_type', 'page_type must be one of: ' . implode(', ', self::PAGE_ITEM_TYPES) . '.');
            }
            if (array_key_exists('route_mode', $item) && !in_array($item['route_mode'], PageAddress::ALLOWED_ROUTE_MODES, true)) {
                $bad('route_mode', 'route_mode must be one of: ' . implode(', ', PageAddress::ALLOWED_ROUTE_MODES) . '.');
            }
        }
        if ($kind === self::KIND_COMPONENT && array_key_exists('source_ref', $item)
            && (!is_string($item['source_ref']) || preg_match(CanonicalDocumentSchema::COMPONENT_REF_PATTERN, $item['source_ref']) !== 1)) {
            $bad('source_ref', 'source_ref must be the component uuid from the exporting site.');
        }
        if ($kind === self::KIND_TEMPLATE) {
            if (array_key_exists('template_key', $item) && (!is_string($item['template_key']) || preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $item['template_key']) !== 1)) {
                $bad('template_key', 'template_key must be a slug.');
            }
            foreach (['template_type' => 32, 'category' => 64, 'name' => 191] as $field => $max) {
                if (array_key_exists($field, $item) && !$plain($item[$field], $max)) {
                    $bad($field, "{$field} must be plain text of at most {$max} characters.");
                }
            }
            if (array_key_exists('description', $item) && $item['description'] !== null && (!is_string($item['description']) || mb_strlen($item['description'], 'UTF-8') > 1000)) {
                $bad('description', 'description must be null or text of at most 1000 characters.');
            }
        }
        if ($kind === self::KIND_TOKENS) {
            if (array_key_exists('token_group', $item) && (!is_string($item['token_group']) || preg_match(CanonicalDocumentSchema::TOKEN_GROUP_PATTERN, $item['token_group']) !== 1)) {
                $bad('token_group', 'token_group must be a token group identifier.');
            }
            $tokens = $item['tokens'] ?? null;
            if (array_key_exists('tokens', $item)) {
                if (!is_array($tokens) || ($tokens !== [] && array_is_list($tokens)) || count($tokens) > self::MAX_TOKENS_PER_ITEM) {
                    $bad('tokens', 'tokens must be an object of at most ' . self::MAX_TOKENS_PER_ITEM . ' token ref => value pairs.');
                } else {
                    foreach ($tokens as $ref => $value) {
                        if (preg_match('/^[a-z][a-z0-9_.]{0,63}$/', (string) $ref) !== 1 || !is_string($value) || strlen($value) > 200) {
                            $bad('tokens.' . self::pathKey((string) $ref), 'Each token must be a symbolic ref with a short string value.');
                        }
                    }
                }
            }
        }
        if (array_key_exists('document', $item) && (!is_array($item['document']) || $item['document'] === [] || array_is_list($item['document']))) {
            $bad('document', 'document must be a canonical Studio document object.');
        }
        if (array_key_exists('media', $item)) {
            $media = $item['media'];
            if (!is_array($media) || !array_is_list($media) || count($media) > self::MAX_MEDIA_PER_ITEM) {
                $bad('media', 'media must be a list of at most ' . self::MAX_MEDIA_PER_ITEM . ' media descriptors.');
            } else {
                $seen = [];
                foreach ($media as $m => $descriptor) {
                    $mp = "media[{$m}]";
                    if (!is_array($descriptor) || array_is_list($descriptor) || array_diff(array_keys($descriptor), self::MEDIA_KEYS) !== [] || count($descriptor) !== count(self::MEDIA_KEYS)) {
                        $bad($mp, 'A media descriptor is exactly {key, path, mime}.');
                        continue;
                    }
                    $k = $descriptor['key'];
                    if (!is_int($k) || $k <= 0 || isset($seen[$k])) {
                        $bad("{$mp}.key", 'A media descriptor key is a unique positive integer local to this item.');
                    } else {
                        $seen[$k] = true;
                    }
                    $p = $descriptor['path'];
                    if (!is_string($p) || strlen($p) > 500 || preg_match('/[\x00-\x1F\x7F]/', $p) === 1 || str_contains($p, '..') || str_contains($p, '\\')
                        || ($p !== '' && !self::isExternalReference($p) && !str_starts_with($p, '/uploads/'))) {
                        $bad("{$mp}.path", 'A media path is a site-relative /uploads/ path (no filesystem paths, no traversal).');
                    }
                    if (!is_string($descriptor['mime']) || strlen($descriptor['mime']) > 120 || preg_match('#^([a-z0-9.+-]+/[a-z0-9.+-]+)?$#i', $descriptor['mime']) !== 1) {
                        $bad("{$mp}.mime", 'mime must be a media type such as image/jpeg.');
                    }
                }
            }
        }
        return $out;
    }

    /** A reference that points outside this site (never fetched, never stored). */
    public static function isExternalReference(string $path): bool
    {
        return preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path) === 1;
    }

    /**
     * Every JSON path at which a `tenant_id` key occurs (any depth).
     *
     * @return list<string>
     */
    public static function forbiddenTenantPaths(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $isList = array_is_list($value);
        foreach ($value as $k => $v) {
            $child = $isList ? "{$path}[{$k}]" : $path . '.' . self::pathKey((string) $k);
            if (!$isList && (string) $k === 'tenant_id') {
                $out[] = $child;
            }
            foreach (self::forbiddenTenantPaths($v, $child) as $p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * The item's content hash: sha256 over the stable encoding of the item
     * without its `content_hash`. Stable across PHP and browser JSON round
     * trips: keys sorted recursively, lists kept in order, no zero-fraction
     * floats, unescaped slashes/unicode.
     *
     * @param array<string, mixed> $item
     */
    public static function itemHash(array $item): string
    {
        unset($item['content_hash']);
        return hash('sha256', self::stableEncode($item));
    }

    /** sha256 of a whole package's stable encoding (audit / report identity; not a signature). */
    public static function packageHash(array $package): string
    {
        return hash('sha256', self::stableEncode($package));
    }

    public static function stableEncode(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = array_map([self::class, 'sortKeys'], $value);
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }

    private static function pathKey(string $key): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '?', $key) ?? '?', 0, 64, 'UTF-8');
    }
}
