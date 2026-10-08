<?php
/**
 * Kohevo Studio (studio-builder) — Typed Declarative FieldSchema.
 *
 * Defines and validates block property schemas with fail-closed semantics:
 * - Supported field types:
 *     string, text, rich_text, number, boolean, enum, url,
 *     media_ref, token_ref, link, repeater, object
 * - Enforces constraints:
 *     required, default, max_length, min_length, min, max, integer_only,
 *     allowed_values, max_items, item_schema, properties
 * - Rejects unknown property keys in block props and nested object fields.
 * - Never silently coerces invalid scalar/complex types.
 * - Rejects dangerous URL schemes (`javascript:`, `data:`, `vbscript:`),
 *   executable PHP/template/JS expressions, and SQL fragments.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Schema;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;

final class FieldSchema implements \JsonSerializable
{
    public const SUPPORTED_TYPES = [
        'string',
        'text',
        'rich_text',
        'number',
        'boolean',
        'enum',
        'url',
        'media_ref',
        'token_ref',
        'link',
        'repeater',
        'object',
    ];

    public const ALLOWED_LINK_TARGETS = ['_self', '_blank'];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $fieldsByKey = [];

    /**
     * @param list<array<string, mixed>> $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['key']) || !is_string($field['key'])) {
                throw new \InvalidArgumentException('Every FieldSchema field definition must declare a non-empty string key.');
            }
            $key = $field['key'];
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                throw new \InvalidArgumentException("Invalid FieldSchema field key '{$key}'.");
            }
            if ($key === 'tenant_id') {
                throw new \InvalidArgumentException("FieldSchema field key 'tenant_id' is forbidden in canonical documents.");
            }
            if (isset($this->fieldsByKey[$key])) {
                throw new \InvalidArgumentException("Duplicate FieldSchema field key '{$key}'.");
            }

            $type = $field['type'] ?? '';
            if (!is_string($type) || !in_array($type, self::SUPPORTED_TYPES, true)) {
                throw new \InvalidArgumentException("Unsupported FieldSchema type '{$type}' for field '{$key}'.");
            }

            if ($type === 'enum') {
                if (empty($field['allowed_values']) || !is_array($field['allowed_values']) || !array_is_list($field['allowed_values'])) {
                    throw new \InvalidArgumentException("Enum field '{$key}' must declare a non-empty list of allowed_values.");
                }
            }

            if ($type === 'repeater') {
                if (!isset($field['item_schema']) || !($field['item_schema'] instanceof self)) {
                    throw new \InvalidArgumentException("Repeater field '{$key}' must provide an item_schema instance of FieldSchema.");
                }
            }

            if ($type === 'object') {
                if (!isset($field['properties']) || !($field['properties'] instanceof self)) {
                    throw new \InvalidArgumentException("Object field '{$key}' must provide a properties instance of FieldSchema.");
                }
            }

            $this->fieldsByKey[$key] = $field;
        }
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    public static function define(array $fields = []): self
    {
        return new self($fields);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return array_values($this->fieldsByKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->fieldsByKey as $key => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$key] = $field['default'];
            } else {
                $defaults[$key] = self::typeDefault($field);
            }
        }
        return CanonicalJson::sortKeysRecursively($defaults);
    }

    /**
     * Validate a map of block or object properties against this schema.
     * Fails closed on unknown keys, missing required fields, or invalid values.
     *
     * @param array<string, mixed> $props
     */
    public function validate(array $props, string $basePath = '$.props'): ValidationResult
    {
        $errors = [];

        if ($props !== [] && array_is_list($props)) {
            return ValidationResult::fromErrors([
                ValidationResult::issue($basePath, 'invalid_props_object', 'Properties must be a keyed object, not a sequential list.'),
            ]);
        }

        // 1. Reject unknown keys and explicit tenant_id keys
        foreach ($props as $key => $_) {
            $k = (string) $key;
            if ($k === 'tenant_id') {
                $errors[] = ValidationResult::issue("{$basePath}.tenant_id", 'forbidden_tenant_id', 'Canonical document properties must never contain tenant_id.');
                continue;
            }
            if (!isset($this->fieldsByKey[$k])) {
                $errors[] = ValidationResult::issue("{$basePath}.{$k}", 'unknown_property', "Unknown property '{$k}'.");
            }
        }

        // 2. Validate declared fields
        foreach ($this->fieldsByKey as $key => $field) {
            $fieldPath = "{$basePath}.{$key}";
            $required  = (bool) ($field['required'] ?? false);
            $present   = array_key_exists($key, $props);
            $val       = $props[$key] ?? null;

            if (!$present || $val === null) {
                if ($required) {
                    $errors[] = ValidationResult::issue($fieldPath, 'required_field', "Required field '{$key}' is missing or null.");
                }
                continue;
            }

            $this->validateFieldValue($field, $val, $fieldPath, $errors);
        }

        return ValidationResult::fromErrors($errors);
    }

    /**
     * Normalize a validated property map: apply defaults, recursively normalize nested
     * objects and repeaters, and sort keys deterministically.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function normalize(array $props): array
    {
        $out = [];

        foreach ($this->fieldsByKey as $key => $field) {
            $type = (string) $field['type'];
            $hasValue = array_key_exists($key, $props) && $props[$key] !== null;

            if (!$hasValue) {
                if (array_key_exists('default', $field)) {
                    $out[$key] = $field['default'];
                } else {
                    $out[$key] = self::typeDefault($field);
                }
                continue;
            }

            $val = $props[$key];
            $out[$key] = $this->normalizeFieldValue($field, $type, $val);
        }

        return CanonicalJson::sortKeysRecursively($out);
    }

    /**
     * Transport-safe JSON representation for the editor manifest.
     *
     * @return list<array<string, mixed>>
     */
    public function toEditorManifest(): array
    {
        $manifest = [];
        foreach ($this->fieldsByKey as $key => $field) {
            $item = [
                'key'      => $key,
                'type'     => (string) $field['type'],
                'label'    => (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $key))),
                'required' => (bool) ($field['required'] ?? false),
                'default'  => array_key_exists('default', $field) ? $field['default'] : self::typeDefault($field),
            ];

            foreach (['max_length', 'min_length', 'min', 'max', 'integer_only', 'allowed_values', 'max_items'] as $constraint) {
                if (array_key_exists($constraint, $field)) {
                    $item[$constraint] = $field[$constraint];
                }
            }

            if ($field['type'] === 'repeater' && $field['item_schema'] instanceof self) {
                $item['item_schema'] = $field['item_schema']->toEditorManifest();
            }

            if ($field['type'] === 'object' && $field['properties'] instanceof self) {
                $item['properties'] = $field['properties']->toEditorManifest();
            }

            $manifest[] = $item;
        }
        return $manifest;
    }

    public function jsonSerialize(): array
    {
        return $this->toEditorManifest();
    }

    /**
     * Validate a URL string against Studio security rules.
     *
     * Allows:
     * - Relative paths starting with `/` (but NOT protocol-relative `//`)
     * - Fragment anchors starting with `#`
     * - `http://`, `https://`, `mailto:`, `tel:`
     * Rejects:
     * - `javascript:`, `data:`, `vbscript:`, `file:`, `blob:`
     * - Control characters, whitespace-obfuscated schemes, or template expressions
     */
    public static function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || strlen($trimmed) > 2048) {
            return false;
        }

        // Reject ASCII control characters (0x00-0x1F, 0x7F) anywhere in URL
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        if (self::containsExecutableOrSqlFragment($trimmed)) {
            return false;
        }

        // Normalize whitespace/entities before checking scheme
        $decoded = html_entity_decode($trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = (string) preg_replace('/\s+/', '', $decoded);
        if (preg_match('/^(javascript|data|vbscript|file|blob|about):/i', $collapsed)) {
            return false;
        }

        // Relative path `/foo` (not protocol-relative `//foo`)
        if (str_starts_with($trimmed, '/')) {
            return !str_starts_with($trimmed, '//');
        }

        // Anchor fragment `#section`
        if (str_starts_with($trimmed, '#')) {
            return (bool) preg_match('/^#[A-Za-z0-9_.-]+$/', $trimmed);
        }

        if (preg_match('~^https?://[^\s/$.?#].[^\s]*$~i', $trimmed)) {
            return true;
        }

        if (preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i', $trimmed)) {
            return true;
        }

        if (preg_match('/^tel:\+?[0-9() -]{3,32}$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a string contains executable expressions, dangerous HTML/JS constructs,
     * template injection markers, or SQL injection fragments.
     */
    public static function containsExecutableOrSqlFragment(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $compact = (string) preg_replace('/[\x00-\x20]+/', '', $decoded);

        // 1. Dangerous URI schemes or CSS expressions
        if (preg_match('/(javascript|vbscript|data\s*:\s*(text\/html|image\/svg\+xml|application\/javascript))\s*:/i', $compact)) {
            return true;
        }
        if (preg_match('/expression\s*\(/i', $decoded)) {
            return true;
        }

        // 2. PHP tags, server template expressions, or JS eval constructs
        if (preg_match('/(<\?php|<\?=|<script\b|<\/script>|\beval\s*\(|\bFunction\s*\(|\bsetTimeout\s*\(\s*[\'"]|\bsetInterval\s*\(\s*[\'"])/i', $decoded)) {
            return true;
        }
        if (preg_match('/(\$\{[^}]+\}|\{\{\s*\$|\{%|<%)/', $decoded)) {
            return true;
        }

        // 3. SQL fragments
        if (preg_match('/\b(UNION\s+ALL\s+SELECT|UNION\s+SELECT|DROP\s+TABLE|ALTER\s+TABLE|INSERT\s+INTO|DELETE\s+FROM|TRUNCATE\s+TABLE|INFORMATION_SCHEMA|SLEEP\s*\(\s*\d+\s*\)|BENCHMARK\s*\()/i', $decoded)) {
            return true;
        }
        if (preg_match('/;\s*(--|\/\*)\s*/', $decoded)) {
            return true;
        }

        return false;
    }

    /**
     * Validate untrusted rich_text HTML/content.
     *
     * Allows only semantic inline/block formatting tags (`p`, `br`, `strong`, `em`, `b`, `i`,
     * `u`, `s`, `ul`, `ol`, `li`, `blockquote`, `code`, `pre`, `h1`-`h6`, `a`, `span`)
     * with safe `href`/`target`/`rel` on `<a>` only. Rejects `<script>`, `<iframe>`, `<svg>`,
     * `<style>`, `<object>`, `<embed>`, `<form>`, `<input>`, event handlers (`on*=`),
     * `style=` attributes, and dangerous URLs.
     */
    public static function validateRichText(string $html): ?string
    {
        if (mb_strlen($html, 'UTF-8') > CanonicalDocumentSchema::MAX_RICH_TEXT_LENGTH) {
            return 'Rich text exceeds maximum allowed length of ' . CanonicalDocumentSchema::MAX_RICH_TEXT_LENGTH . ' characters.';
        }

        if (self::containsExecutableOrSqlFragment($html)) {
            return 'Rich text contains forbidden executable, script, or SQL patterns.';
        }

        // Reject forbidden HTML elements outright (never silently strip them on write)
        if (preg_match('/<\s*\/?\s*(script|iframe|frame|frameset|object|embed|applet|style|link|meta|base|form|input|button|select|textarea|svg|math|audio|video|canvas)\b/i', $html)) {
            return 'Rich text contains disallowed HTML elements.';
        }

        // Reject any inline event handler (`onload=`, `onerror=`, `onclick=`, etc.) or `style=` / `srcdoc=`
        if (preg_match('/\s(on[a-z]+|style|srcdoc|formaction)\s*=/i', $html)) {
            return 'Rich text contains disallowed HTML event handler or inline style attributes.';
        }

        // Inspect all tags and attributes
        $allowedTags = [
            'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's',
            'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span',
        ];

        if (preg_match_all('/<\s*(\/?)\s*([a-zA-Z0-9-]+)([^>]*)>/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $isClosing = $m[1] === '/';
                $tag = strtolower($m[2]);
                $attrString = trim($m[3]);

                if (!in_array($tag, $allowedTags, true)) {
                    return "Rich text contains unsupported HTML tag '<{$tag}>'.";
                }

                if ($isClosing) {
                    if ($attrString !== '') {
                        return "Closing HTML tag '</{$tag}>' must not contain attributes.";
                    }
                    continue;
                }

                // Self-closing slash on `<br/>`
                if ($tag === 'br') {
                    $cleaned = trim($attrString, '/ ');
                    if ($cleaned !== '') {
                        return 'The <br> tag must not contain attributes.';
                    }
                    continue;
                }

                if ($tag !== 'a' && $attrString !== '') {
                    return "HTML tag '<{$tag}>' does not permit attributes in Studio rich_text.";
                }

                if ($tag === 'a' && $attrString !== '') {
                    if (!self::validateAnchorAttributes($attrString)) {
                        return 'Anchor tag <a> in rich_text contains invalid or unsafe attributes.';
                    }
                }
            }
        }

        return null;
    }

    private static function validateAnchorAttributes(string $attrString): bool
    {
        $remaining = trim($attrString);
        while ($remaining !== '') {
            if (!preg_match('/^([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')\s*(.*)$/s', $remaining, $m)) {
                return false;
            }
            $attrName = strtolower($m[1]);
            $attrVal  = $m[2] !== '' ? $m[2] : $m[3];
            $remaining = trim($m[4]);

            if (!in_array($attrName, ['href', 'target', 'rel'], true)) {
                return false;
            }
            if ($attrName === 'href' && !self::isSafeUrl($attrVal)) {
                return false;
            }
            if ($attrName === 'target' && !in_array($attrVal, self::ALLOWED_LINK_TARGETS, true)) {
                return false;
            }
            if ($attrName === 'rel' && !preg_match('/^[a-zA-Z0-9 _-]+$/', $attrVal)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $field
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private function validateFieldValue(array $field, mixed $val, string $path, array &$errors): void
    {
        $type = (string) $field['type'];

        switch ($type) {
            case 'string':
                if (!is_string($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Expected string.');
                    return;
                }
                if (preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_string_chars', 'Single-line string field must not contain newlines or control characters.');
                    return;
                }
                $maxLen = (int) ($field['max_length'] ?? CanonicalDocumentSchema::MAX_STRING_LENGTH);
                $minLen = (int) ($field['min_length'] ?? 0);
                $len    = mb_strlen($val, 'UTF-8');
                if ($len > $maxLen) {
                    $errors[] = ValidationResult::issue($path, 'max_length_exceeded', "String exceeds maximum length of {$maxLen}.");
                }
                if ($len < $minLen) {
                    $errors[] = ValidationResult::issue($path, 'min_length_not_met', "String is shorter than minimum length of {$minLen}.");
                }
                if (isset($field['pattern']) && is_string($field['pattern']) && preg_match($field['pattern'], $val) !== 1) {
                    $errors[] = ValidationResult::issue($path, 'pattern_mismatch', 'String does not match required pattern.');
                }
                if (self::containsExecutableOrSqlFragment($val)) {
                    $errors[] = ValidationResult::issue($path, 'unsafe_content', 'String contains forbidden executable or SQL patterns.');
                }
                break;

            case 'text':
                if (!is_string($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Expected text string.');
                    return;
                }
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_text_chars', 'Text field must not contain binary control characters.');
                    return;
                }
                $maxLen = (int) ($field['max_length'] ?? CanonicalDocumentSchema::MAX_TEXT_LENGTH);
                if (mb_strlen($val, 'UTF-8') > $maxLen) {
                    $errors[] = ValidationResult::issue($path, 'max_length_exceeded', "Text exceeds maximum length of {$maxLen}.");
                }
                if (self::containsExecutableOrSqlFragment($val)) {
                    $errors[] = ValidationResult::issue($path, 'unsafe_content', 'Text contains forbidden executable or SQL patterns.');
                }
                break;

            case 'rich_text':
                if (!is_string($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Expected rich_text string.');
                    return;
                }
                $richErr = self::validateRichText($val);
                if ($richErr !== null) {
                    $errors[] = ValidationResult::issue($path, 'unsafe_rich_text', $richErr);
                }
                break;

            case 'number':
                if (!is_int($val) && !is_float($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Expected number (integer or float).');
                    return;
                }
                if (is_nan((float) $val) || is_infinite((float) $val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_number', 'Number must be finite.');
                    return;
                }
                if (!empty($field['integer_only']) && !is_int($val)) {
                    $errors[] = ValidationResult::issue($path, 'integer_required', 'Expected integer value.');
                    return;
                }
                if (isset($field['min']) && $val < $field['min']) {
                    $errors[] = ValidationResult::issue($path, 'min_value', "Number must be >= {$field['min']}.");
                }
                if (isset($field['max']) && $val > $field['max']) {
                    $errors[] = ValidationResult::issue($path, 'max_value', "Number must be <= {$field['max']}.");
                }
                break;

            case 'boolean':
                if (!is_bool($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Expected boolean.');
                }
                break;

            case 'enum':
                $allowed = (array) ($field['allowed_values'] ?? []);
                if (!is_scalar($val) || !in_array($val, $allowed, true)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_enum_value', 'Value is not in the allowed enum set.');
                }
                break;

            case 'url':
                if (!is_string($val) || !self::isSafeUrl($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_url', 'Value must be a safe, allowlisted URL or relative path.');
                }
                break;

            case 'token_ref':
                if (!is_string($val) || preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $val) !== 1) {
                    $errors[] = ValidationResult::issue($path, 'invalid_token_ref', 'Value must be a valid symbolic design token reference.');
                }
                break;

            case 'media_ref':
                $this->validateMediaRef($val, $path, $errors);
                break;

            case 'link':
                $this->validateLink($val, $path, $errors);
                break;

            case 'repeater':
                if (!is_array($val) || !array_is_list($val)) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Repeater field must be a sequential list.');
                    return;
                }
                $maxItems = (int) ($field['max_items'] ?? CanonicalDocumentSchema::MAX_REPEATER_ITEMS);
                if (count($val) > $maxItems) {
                    $errors[] = ValidationResult::issue($path, 'max_items_exceeded', "Repeater exceeds maximum of {$maxItems} items.");
                    return;
                }
                /** @var self $itemSchema */
                $itemSchema = $field['item_schema'];
                foreach ($val as $idx => $item) {
                    $itemPath = "{$path}[{$idx}]";
                    if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                        $errors[] = ValidationResult::issue($itemPath, 'invalid_repeater_item', 'Repeater item must be an object.');
                        continue;
                    }
                    $res = $itemSchema->validate($item, $itemPath);
                    foreach ($res->errors() as $err) {
                        $errors[] = $err;
                    }
                }
                break;

            case 'object':
                if (!is_array($val) || ($val !== [] && array_is_list($val))) {
                    $errors[] = ValidationResult::issue($path, 'invalid_type', 'Object field must be a keyed associative array.');
                    return;
                }
                /** @var self $propSchema */
                $propSchema = $field['properties'];
                $res = $propSchema->validate($val, $path);
                foreach ($res->errors() as $err) {
                    $errors[] = $err;
                }
                break;
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    public function validateMediaRef(mixed $val, string $path, array &$errors): void
    {
        if (!is_array($val) || array_is_list($val)) {
            $errors[] = ValidationResult::issue($path, 'invalid_media_ref', 'media_ref must be an object with {media_id, alt, focal_point?}.');
            return;
        }

        $allowedKeys = ['media_id', 'alt', 'focal_point'];
        foreach (array_keys($val) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                $errors[] = ValidationResult::issue("{$path}.tenant_id", 'forbidden_tenant_id', 'media_ref must not contain tenant_id.');
                continue;
            }
            if (!in_array($ks, $allowedKeys, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown media_ref property '{$ks}'.");
            }
        }

        $mediaId = $val['media_id'] ?? null;
        if (!is_int($mediaId) || $mediaId <= 0) {
            $errors[] = ValidationResult::issue("{$path}.media_id", 'invalid_media_id', 'media_ref.media_id must be a positive integer.');
        }

        $alt = $val['alt'] ?? null;
        if (!is_string($alt) || mb_strlen($alt, 'UTF-8') > 500 || self::containsExecutableOrSqlFragment($alt)) {
            $errors[] = ValidationResult::issue("{$path}.alt", 'invalid_media_alt', 'media_ref.alt must be a safe string <= 500 characters.');
        }

        if (array_key_exists('focal_point', $val) && $val['focal_point'] !== null) {
            $fp = $val['focal_point'];
            if (
                !is_array($fp)
                || !array_is_list($fp)
                || count($fp) !== 2
                || (!is_int($fp[0]) && !is_float($fp[0]))
                || (!is_int($fp[1]) && !is_float($fp[1]))
                || $fp[0] < 0.0 || $fp[0] > 1.0
                || $fp[1] < 0.0 || $fp[1] > 1.0
            ) {
                $errors[] = ValidationResult::issue("{$path}.focal_point", 'invalid_focal_point', 'media_ref.focal_point must be a [x, y] pair in range [0.0, 1.0].');
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private function validateLink(mixed $val, string $path, array &$errors): void
    {
        if (!is_array($val) || array_is_list($val)) {
            $errors[] = ValidationResult::issue($path, 'invalid_link', 'link field must be an object with {label, href, target?, rel?}.');
            return;
        }

        $allowedKeys = ['label', 'href', 'target', 'rel'];
        foreach (array_keys($val) as $k) {
            $ks = (string) $k;
            if (!in_array($ks, $allowedKeys, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown link property '{$ks}'.");
            }
        }

        $label = $val['label'] ?? null;
        if (!is_string($label) || trim($label) === '' || mb_strlen($label, 'UTF-8') > 255 || self::containsExecutableOrSqlFragment($label)) {
            $errors[] = ValidationResult::issue("{$path}.label", 'invalid_link_label', 'link.label must be a non-empty safe string <= 255 chars.');
        }

        $href = $val['href'] ?? null;
        if (!is_string($href) || !self::isSafeUrl($href)) {
            $errors[] = ValidationResult::issue("{$path}.href", 'invalid_link_href', 'link.href must be a safe URL or relative path.');
        }

        if (isset($val['target']) && (!is_string($val['target']) || !in_array($val['target'], self::ALLOWED_LINK_TARGETS, true))) {
            $errors[] = ValidationResult::issue("{$path}.target", 'invalid_link_target', 'link.target must be _self or _blank.');
        }

        if (isset($val['rel']) && $val['rel'] !== null && (!is_string($val['rel']) || !preg_match('/^[a-zA-Z0-9 _-]{0,64}$/', $val['rel']))) {
            $errors[] = ValidationResult::issue("{$path}.rel", 'invalid_link_rel', 'link.rel contains invalid characters.');
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function normalizeFieldValue(array $field, string $type, mixed $val): mixed
    {
        switch ($type) {
            case 'string':
            case 'text':
            case 'rich_text':
            case 'url':
            case 'token_ref':
                return (string) $val;

            case 'number':
            case 'boolean':
            case 'enum':
                return $val;

            case 'media_ref':
                if (!is_array($val)) {
                    return null;
                }
                $fp = $val['focal_point'] ?? [0.5, 0.5];
                return [
                    'alt'         => (string) ($val['alt'] ?? ''),
                    'focal_point' => [(float) $fp[0], (float) $fp[1]],
                    'media_id'    => (int) $val['media_id'],
                ];

            case 'link':
                if (!is_array($val)) {
                    return null;
                }
                $target = (string) ($val['target'] ?? '_self');
                $rel    = $val['rel'] ?? ($target === '_blank' ? 'noopener noreferrer' : null);
                return [
                    'href'   => trim((string) $val['href']),
                    'label'  => (string) $val['label'],
                    'rel'    => $rel !== null ? (string) $rel : null,
                    'target' => $target,
                ];

            case 'repeater':
                /** @var self $itemSchema */
                $itemSchema = $field['item_schema'];
                $items = [];
                foreach ((array) $val as $item) {
                    if (is_array($item)) {
                        $items[] = $itemSchema->normalize($item);
                    }
                }
                return $items;

            case 'object':
                /** @var self $propSchema */
                $propSchema = $field['properties'];
                return $propSchema->normalize(is_array($val) ? $val : []);

            default:
                return $val;
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function typeDefault(array $field): mixed
    {
        return match ((string) $field['type']) {
            'string', 'text', 'rich_text' => '',
            'number'                      => !empty($field['integer_only']) ? 0 : 0.0,
            'boolean'                     => false,
            'enum'                        => $field['allowed_values'][0] ?? null,
            'url', 'token_ref', 'media_ref', 'link' => null,
            'repeater'                    => [],
            'object'                      => ($field['properties'] instanceof self) ? $field['properties']->defaults() : [],
            default                       => null,
        };
    }
}
