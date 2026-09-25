<?php
/**
 * Slate — Phase 2 document validator.
 *
 * Validates editor writes against DocumentSchema v1 and produces a canonical,
 * transient-state-free document. It is pure: persistence and content_html
 * compilation belong to the save/publish application service.
 */
declare(strict_types=1);

namespace Slate\Presentation;

final class DocumentValidator
{
    public const MAX_SECTIONS = 200;
    public const MAX_BLOCKS_PER_SECTION = 100;
    public const MAX_TOTAL_BLOCKS = 1000;
    public const MAX_NESTING_DEPTH = 8;
    public const MAX_PROPS = 128;
    public const MAX_REPEATER_ITEMS = 100;
    public const MAX_SEO_PROPERTIES = 64;
    public const MAX_STRING_LENGTH = 100000;

    /**
     * @param string|array|null $stored
     * @param array<string,array<string,mixed>> $registry block metadata keyed by type
     * @param array{allow_unknown_existing?:bool,existing?:array<string,mixed>,type?:string} $options
     * @return array{valid:bool,document:?array,errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    public static function validate(string|array|null $stored, array $registry = [], array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $decoded = self::decode($stored, $errors);
        if ($decoded === null) {
            return self::result(null, $errors, $warnings);
        }

        $type = (string)($options['type'] ?? 'page');
        $document = self::normalizeEnvelope($decoded, $type, $errors);
        if ($document === null) {
            return self::result(null, $errors, $warnings);
        }

        self::validateDocument($document, $registry, $errors, $warnings, $options);
        if ($errors !== []) {
            return self::result(null, $errors, $warnings);
        }
        return self::result($document, $errors, $warnings);
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public static function normalizeAndValidate(array $document, array $registry = [], array $options = []): array
    {
        return self::validate($document, $registry, $options);
    }

    /** @param list<array<string,mixed>> $errors @return array<string,mixed>|null */
    private static function decode(string|array|null $stored, array &$errors): ?array
    {
        if (is_array($stored)) return $stored;
        if ($stored === null || $stored === '') return [];
        try {
            $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = self::issue('', 'invalid_json', 'Document is not valid JSON.');
            return null;
        }
        if (!is_array($decoded)) {
            $errors[] = self::issue('', 'type', 'Document must decode to an object or legacy block list.');
            return null;
        }
        return $decoded;
    }

    /** @param array<string,mixed> $decoded @param list<array<string,mixed>> $errors */
    private static function normalizeEnvelope(array $decoded, string $type, array &$errors): ?array
    {
        $isEnvelope = !array_is_list($decoded)
            && (array_key_exists('schema', $decoded) || array_key_exists('sections', $decoded));

        if (!$isEnvelope) {
            $legacy = DocumentSchema::normalize($decoded, $type);
            return $legacy;
        }

        $allowed = ['schema', 'type', 'template', 'sections', 'seo'];
        foreach (array_keys($decoded) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                $errors[] = self::issue((string)$key, 'unknown_property', 'Unknown document property.');
            }
        }
        if (($decoded['schema'] ?? null) !== DocumentSchema::VERSION) {
            $errors[] = self::issue('schema', 'unsupported_schema', 'Document schema version is not supported.');
        }
        $canonicalType = (string)($decoded['type'] ?? $type);
        $template = (string)($decoded['template'] ?? '');
        $sections = $decoded['sections'] ?? [];
        $seo = $decoded['seo'] ?? [];
        if (!is_array($sections) || !array_is_list($sections)) {
            $errors[] = self::issue('sections', 'type', 'Sections must be an array.');
            $sections = [];
        }
        if (!is_array($seo) || ($seo !== [] && array_is_list($seo))) {
            $errors[] = self::issue('seo', 'type', 'SEO metadata must be an object.');
            $seo = [];
        }
        $out = [
            'schema' => DocumentSchema::VERSION,
            'type' => $canonicalType,
            'template' => $template,
            'sections' => [],
            'seo' => $seo,
        ];
        foreach ($sections as $i => $section) {
            if (!is_array($section)) {
                $errors[] = self::issue("sections[$i]", 'type', 'Section must be an object.');
                continue;
            }
            $id = isset($section['id']) && $section['id'] !== '' ? (string)$section['id'] : 's' . ($i + 1);
            $layout = $section['layout'] ?? LayoutSpec::default()->toArray();
            $blocks = $section['blocks'] ?? [];
            $entry = ['id' => $id, 'layout' => is_array($layout) ? $layout : [], 'blocks' => is_array($blocks) ? $blocks : []];
            if (array_key_exists('savedAs', $section)) $entry['savedAs'] = $section['savedAs'];
            $out['sections'][] = $entry;
        }
        return $out;
    }

    /** @param array<string,mixed> $document @param array<string,array<string,mixed>> $registry @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
    private static function validateDocument(array &$document, array $registry, array &$errors, array &$warnings, array $options): void
    {
        self::stringPattern($document['type'], 'type', '/^[a-z][a-z0-9_-]{0,63}$/', $errors);
        self::stringPattern($document['template'], 'template', '/^(|[a-z][a-z0-9_-]{0,63})$/', $errors);
        if (count($document['sections']) > self::MAX_SECTIONS) self::error($errors, 'sections', 'max_items', 'Too many sections.');
        if (count($document['seo']) > self::MAX_SEO_PROPERTIES) self::error($errors, 'seo', 'max_properties', 'Too many SEO properties.');

        $seen = [];
        $total = 0;
        foreach ($document['sections'] as $si => &$section) {
            $path = "sections[$si]";
            self::validateSection($section, $path, $seen, $registry, $errors, $warnings, $total, $options, 0);
        }
        unset($section);
        if ($total > self::MAX_TOTAL_BLOCKS) self::error($errors, 'sections', 'max_blocks', 'Document contains too many blocks.');
    }

    /** @param array<string,mixed> $section @param array<string,bool> $seen @param array<string,array<string,mixed>> $registry @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
    private static function validateSection(array &$section, string $path, array &$seen, array $registry, array &$errors, array &$warnings, int &$total, array $options, int $depth): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array((string)$key, ['id', 'layout', 'blocks', 'savedAs'], true)) self::error($errors, "$path.$key", 'unknown_property', 'Unknown section property.');
        }
        $id = (string)($section['id'] ?? '');
        self::stringPattern($id, "$path.id", '/^s[1-9][0-9]{0,5}$/', $errors);
        if (isset($seen[$id])) self::error($errors, "$path.id", 'duplicate', 'Section IDs must be unique.');
        $seen[$id] = true;
        self::validateLayout($section['layout'] ?? [], "$path.layout", $errors);
        if (array_key_exists('savedAs', $section)) self::validateAlias($section['savedAs'], "$path.savedAs", $errors);
        $blocks = $section['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks)) {
            self::error($errors, "$path.blocks", 'type', 'Blocks must be an array.');
            return;
        }
        if (count($blocks) > self::MAX_BLOCKS_PER_SECTION) self::error($errors, "$path.blocks", 'max_items', 'Too many blocks in section.');
        foreach ($blocks as $bi => &$block) {
            $total++;
            self::validateBlock($block, "$path.blocks[$bi]", $registry, $errors, $warnings, $options, $depth);
        }
        unset($block);
    }

    /** @param mixed $block @param array<string,array<string,mixed>> $registry @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
    private static function validateBlock(mixed &$block, string $path, array $registry, array &$errors, array &$warnings, array $options, int $depth): void
    {
        if (!is_array($block) || array_is_list($block)) { self::error($errors, $path, 'type', 'Block must be an object.'); return; }
        foreach (array_keys($block) as $key) if (!in_array((string)$key, ['type', 'props', 'style'], true)) self::error($errors, "$path.$key", 'unknown_property', 'Unknown block property.');
        $type = (string)($block['type'] ?? '');
        self::stringPattern($type, "$path.type", '/^[a-z][a-z0-9_.-]{0,127}$/', $errors);
        if (!isset($registry[$type])) {
            if (($options['allow_unknown_existing'] ?? false) === true && self::matchesExistingUnknown($path, $type, $block, $options['existing'] ?? null)) {
                $warnings[] = self::issue($path . '.type', 'unknown_block_preserved', 'Unchanged legacy block was preserved for compatibility.');
                return;
            }
            self::error($errors, "$path.type", 'unknown_block', 'Block type is not registered.');
            return;
        }
        $meta = $registry[$type];
        $props = $block['props'] ?? null;
        if (!is_array($props) || ($props !== [] && array_is_list($props))) { self::error($errors, "$path.props", 'type', 'Block props must be an object.'); return; }
        if (count($props) > self::MAX_PROPS) self::error($errors, "$path.props", 'max_properties', 'Too many block properties.');
        self::validateProps($props, $meta, "$path.props", $errors, $warnings);
        if ($type === 'global_ref' && array_key_exists('$ref', $props)) self::validateAlias($props['$ref'], $path . '.props.$ref', $errors);
        if (isset($meta['capabilities']['nested']) && $meta['capabilities']['nested'] === true) {
            self::validateNestedProps($props, $meta, "$path.props", $registry, $errors, $warnings, $options, $depth + 1);
        }
        if (array_key_exists('style', $block)) self::validateStyle($block['style'], "$path.style", $errors);
    }

    /** @param array<string,mixed> $props @param array<string,mixed> $meta @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
    private static function validateProps(array &$props, array $meta, string $path, array &$errors, array &$warnings): void
    {
        $fields = [];
        foreach ((array)($meta['fields'] ?? []) as $field) if (is_array($field) && isset($field['key'])) $fields[(string)$field['key']] = $field;
        $defaults = is_array($meta['defaults'] ?? null) ? $meta['defaults'] : [];
        $props = array_merge($defaults, $props);
        foreach (array_keys($props) as $key) {
            if (!isset($fields[$key]) && !str_starts_with((string)$key, 'x-')) self::error($errors, "$path.$key", 'unknown_property', 'Property is not declared by the block schema.');
        }
        foreach ($fields as $key => $field) {
            if (($field['required'] ?? false) && (!array_key_exists($key, $props) || $props[$key] === '')) self::error($errors, "$path.$key", 'required', 'Required field is missing.');
            if (array_key_exists($key, $props)) self::validateField($props[$key], $field, "$path.$key", $errors);
        }
    }

    /** @param mixed $value @param array<string,mixed> $field @param list<array<string,mixed>> $errors */
    private static function validateField(mixed $value, array $field, string $path, array &$errors): void
    {
        $responsive = (bool)($field['responsive'] ?? false);
        if (is_array($value) && ($value === [] || !array_is_list($value)) && (isset($value['base'], $value['sm'], $value['md'], $value['lg']) || $responsive)) {
            if (!$responsive) { self::error($errors, $path, 'responsive_not_allowed', 'Field does not support responsive values.'); return; }
            foreach ($value as $bp => $v) {
                if (!in_array($bp, ['base', 'sm', 'md', 'lg'], true)) self::error($errors, "$path.$bp", 'breakpoint', 'Unknown responsive breakpoint.');
                else self::validateScalarField($v, $field, "$path.$bp", $errors);
            }
            if ($value === []) self::error($errors, $path, 'empty', 'Responsive value cannot be empty.');
            return;
        }
        self::validateScalarField($value, $field, $path, $errors);
    }

    /** @param mixed $value @param array<string,mixed> $field @param list<array<string,mixed>> $errors */
    private static function validateScalarField(mixed $value, array $field, string $path, array &$errors): void
    {
        $type = (string)($field['type'] ?? 'text');
        $ok = match ($type) {
            'text', 'textarea', 'richtext', 'url', 'colorToken', 'icon' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'select' => is_string($value) || is_int($value),
            'media', 'postReference' => is_array($value) && !array_is_list($value),
            'repeater', 'blocks' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$ok) { self::error($errors, $path, 'type', 'Field value has the wrong type.'); return; }
        if (is_string($value)) {
            $max = min((int)($field['maxLength'] ?? self::MAX_STRING_LENGTH), self::MAX_STRING_LENGTH);
            if (strlen($value) > $max) self::error($errors, $path, 'max_length', 'Field value is too long.');
            if (isset($field['minLength']) && strlen($value) < (int)$field['minLength']) self::error($errors, $path, 'min_length', 'Field value is too short.');
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) self::error($errors, $path, 'control_character', 'Field contains a forbidden control character.');
            if (isset($field['pattern']) && preg_match((string)$field['pattern'], $value) !== 1) self::error($errors, $path, 'pattern', 'Field value has an invalid format.');
        }
        if (is_int($value) || is_float($value)) {
            if (isset($field['integer']) && $field['integer'] === true && !is_int($value)) self::error($errors, $path, 'integer', 'Field value must be an integer.');
            if (isset($field['min']) && $value < $field['min']) self::error($errors, $path, 'min', 'Field value is below the minimum.');
            if (isset($field['max']) && $value > $field['max']) self::error($errors, $path, 'max', 'Field value exceeds the maximum.');
        }
        if (is_array($value) && array_is_list($value)) {
            if (isset($field['minItems']) && count($value) < (int)$field['minItems']) self::error($errors, $path, 'min_items', 'Field contains too few items.');
            $maxItems = min((int)($field['maxItems'] ?? PHP_INT_MAX), self::MAX_REPEATER_ITEMS);
            if (count($value) > $maxItems) self::error($errors, $path, 'max_items', 'Field contains too many items.');
        }
        if ($type === 'select' && isset($field['options'])) {
            $allowed = array_map(static fn($o) => is_array($o) ? ($o['value'] ?? $o['v'] ?? null) : $o, (array)$field['options']);
            if (!in_array($value, $allowed, true)) self::error($errors, $path, 'enum', 'Field value is not an allowed option.');
        }
        if ($type === 'url' && is_string($value) && preg_match('/^(javascript|data|file|vbscript):/i', trim($value))) self::error($errors, $path, 'unsafe_url', 'URL scheme is not allowed.');
        if ($type === 'media') self::validateMedia($value, $path, $errors);
        if ($type === 'colorToken' && is_string($value) && !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $value)) self::error($errors, $path, 'token', 'Color must be a design-token name.');
        if ($type === 'repeater') {
            $itemFields = (array)($field['item']['fields'] ?? $field['item'] ?? []);
            foreach ($value as $i => $item) {
                if (!is_array($item) || array_is_list($item)) { self::error($errors, "$path[$i]", 'type', 'Repeater item must be an object.'); continue; }
                $itemMap = [];
                foreach ($itemFields as $itemField) if (is_array($itemField) && isset($itemField['key'])) $itemMap[(string)$itemField['key']] = $itemField;
                foreach ($item as $itemKey => $_) if (!isset($itemMap[$itemKey])) self::error($errors, "$path[$i].$itemKey", 'unknown_property', 'Repeater property is not declared.');
                foreach ($itemMap as $itemKey => $itemField) {
                    if (($itemField['required'] ?? false) && (!array_key_exists($itemKey, $item) || $item[$itemKey] === '')) self::error($errors, "$path[$i].$itemKey", 'required', 'Required repeater field is missing.');
                    if (array_key_exists($itemKey, $item)) self::validateField($item[$itemKey], $itemField, "$path[$i].$itemKey", $errors);
                }
            }
        }
    }

    /** @param array<string,mixed> $value @param list<array<string,mixed>> $errors */
    private static function validateMedia(array $value, string $path, array &$errors): void
    {
        // A media key is either a short logical alias (e.g. "hero.primary",
        // this codebase's own convention) or a real upload path under
        // uploads/ (e.g. "2026/09/photo.jpg", what the Media Library picker
        // actually returns — see LegacyBlockBridge::resolveMediaUrl(), which
        // this must stay compatible with) — so '/' is allowed, but '..' is
        // rejected outright as defense-in-depth alongside resolveMediaUrl()'s
        // own traversal check.
        if (
            !isset($value['key']) || !is_string($value['key'])
            || str_contains($value['key'], '..')
            || !preg_match('#^[a-z][a-z0-9_./-]{0,254}$#', $value['key'])
        ) {
            self::error($errors, "$path.key", 'media_key', 'Media key is invalid.');
        }
        if (!array_key_exists('alt', $value)) self::error($errors, "$path.alt", 'required', 'Media alt text is required.');
        if (isset($value['alt']) && !is_string($value['alt'])) self::error($errors, "$path.alt", 'type', 'Media alt text must be a string.');
        if (isset($value['alt']) && is_string($value['alt'])) {
            if (strlen($value['alt']) > 1000) self::error($errors, "$path.alt", 'max_length', 'Media alt text is too long.');
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value['alt'])) self::error($errors, "$path.alt", 'control_character', 'Media alt text contains a forbidden control character.');
        }
        if (isset($value['focal'])) {
            if (!is_array($value['focal']) || count($value['focal']) !== 2) self::error($errors, "$path.focal", 'focal', 'Media focal point must contain two values.');
            else foreach ($value['focal'] as $i => $n) if ((!is_int($n) && !is_float($n)) || $n < 0 || $n > 1) self::error($errors, "$path.focal[$i]", 'focal', 'Media focal coordinates must be numeric values between 0 and 1.');
        }
    }

    /** @param array<string,mixed> $props @param array<string,mixed> $meta @param array<string,array<string,mixed>> $registry @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
    private static function validateNestedProps(array $props, array $meta, string $path, array $registry, array &$errors, array &$warnings, array $options, int $depth): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) { self::error($errors, $path, 'max_depth', 'Nested block depth is too great.'); return; }
        foreach ((array)($meta['nestedKeys'] ?? ['children', 'blocks']) as $key) {
            if (!array_key_exists($key, $props)) continue;
            if (!is_array($props[$key]) || !array_is_list($props[$key])) { self::error($errors, "$path.$key", 'type', 'Nested blocks must be an array.'); continue; }
            foreach ($props[$key] as $i => &$child) self::validateBlock($child, "$path.$key[$i]", $registry, $errors, $warnings, $options, $depth);
            unset($child);
        }
    }

    /** @param array<string,mixed>|null $existing @param array<string,mixed> $block */
    private static function matchesExistingUnknown(string $path, string $type, array $block, mixed $existing): bool
    {
        if (!is_array($existing) || !preg_match('/^sections\[(\d+)\]\.blocks\[(\d+)\]$/', $path, $match)) return false;
        $candidate = $existing['sections'][(int)$match[1]]['blocks'][(int)$match[2]] ?? null;
        return is_array($candidate) && ($candidate['type'] ?? null) === $type && $candidate === $block;
    }

    /** @param mixed $layout @param list<array<string,mixed>> $errors */
    private static function validateLayout(mixed $layout, string $path, array &$errors): void
    {
        if (!is_array($layout) || ($layout !== [] && array_is_list($layout))) { self::error($errors, $path, 'type', 'Layout must be an object.'); return; }
        foreach (array_keys($layout) as $key) if (!in_array((string)$key, ['cols', 'bg', 'pad', 'width'], true)) self::error($errors, "$path.$key", 'unknown_property', 'Unknown layout property.');
        $cols = $layout['cols'] ?? 1;
        if (!is_int($cols) || $cols < 1 || $cols > 12) self::error($errors, "$path.cols", 'range', 'Columns must be an integer from 1 to 12.');
        $bg = (string)($layout['bg'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $bg) && $bg !== '') self::error($errors, "$path.bg", 'token', 'Background must be a design-token name.');
        if (!in_array($layout['pad'] ?? 'normal', ['compact', 'normal', 'spacious'], true)) self::error($errors, "$path.pad", 'enum', 'Invalid padding token.');
        if (!in_array($layout['width'] ?? 'normal', ['narrow', 'normal', 'wide', 'full'], true)) self::error($errors, "$path.width", 'enum', 'Invalid width token.');
    }

    /** @param mixed $style @param list<array<string,mixed>> $errors */
    /** @var list<string> style keys the editor's Style panel (admin/editor.php's writeStyle()) actually writes */
    private const STYLE_KEYS = [
        'visible', 'align', 'className',
        'textAlign', 'bgColor', 'textColor', 'bgImage', 'bgOverlay', 'bgOpacity',
        'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight',
        'marginTop', 'marginBottom', 'borderRadius', 'maxWidth', 'customClass',
        'hideDesktop', 'hideTablet', 'hideMobile',
        'cssId', 'zIndex',
    ];

    private static function validateStyle(mixed $style, string $path, array &$errors): void
    {
        if (!is_array($style) || ($style !== [] && array_is_list($style))) { self::error($errors, $path, 'type', 'Style must be an object.'); return; }
        foreach (array_keys($style) as $key) {
            if ($key !== 'responsive' && !in_array((string)$key, self::STYLE_KEYS, true)) self::error($errors, "$path.$key", 'unknown_property', 'Unknown style property.');
        }
        self::validateStyleFields($style, $path, $errors);
        if (isset($style['responsive'])) {
            if (!is_array($style['responsive']) || array_is_list($style['responsive'])) {
                self::error($errors, "$path.responsive", 'type', 'Responsive style overrides must be an object.');
            } else {
                foreach ($style['responsive'] as $bp => $override) {
                    if (!in_array($bp, ['tablet', 'mobile'], true)) { self::error($errors, "$path.responsive.$bp", 'breakpoint', 'Unknown responsive breakpoint.'); continue; }
                    if (!is_array($override) || array_is_list($override)) { self::error($errors, "$path.responsive.$bp", 'type', 'Responsive style override must be an object.'); continue; }
                    foreach (array_keys($override) as $key) {
                        if (!in_array((string)$key, self::STYLE_KEYS, true)) self::error($errors, "$path.responsive.$bp.$key", 'unknown_property', 'Unknown style property.');
                    }
                    self::validateStyleFields($override, "$path.responsive.$bp", $errors);
                }
            }
        }
    }

    /** @param array<string,mixed> $style @param list<array<string,mixed>> $errors */
    private static function validateStyleFields(array $style, string $path, array &$errors): void
    {
        if (isset($style['visible'])) self::validateResponsiveEnum($style['visible'], "$path.visible", [true, false], $errors);
        if (isset($style['align'])) self::validateResponsiveEnum($style['align'], "$path.align", ['start', 'center', 'end'], $errors);
        if (isset($style['className']) && (!is_string($style['className']) || !preg_match('/^[A-Za-z0-9_ -]{0,120}$/', $style['className']))) self::error($errors, "$path.className", 'class_name', 'Class name is invalid.');
        if (isset($style['textAlign']) && !in_array($style['textAlign'], ['left', 'center', 'right', 'justify'], true)) self::error($errors, "$path.textAlign", 'enum', 'Text align must be left, center, right, or justify.');
        foreach (['bgColor', 'textColor'] as $colorKey) {
            if (isset($style[$colorKey]) && (!is_string($style[$colorKey]) || $style[$colorKey] === '')) continue;
            if (isset($style[$colorKey]) && !preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$style[$colorKey])) self::error($errors, "$path.$colorKey", 'color', 'Color must be a hex value.');
        }
        if (isset($style['bgImage']) && $style['bgImage'] !== '') {
            if (!is_string($style['bgImage'])) self::error($errors, "$path.bgImage", 'type', 'Background image must be a URL string.');
            elseif (preg_match('/^(javascript|data|file|vbscript):/i', trim($style['bgImage']))) self::error($errors, "$path.bgImage", 'unsafe_url', 'URL scheme is not allowed.');
            elseif (strlen($style['bgImage']) > 2048) self::error($errors, "$path.bgImage", 'max_length', 'Background image URL is too long.');
        }
        if (isset($style['bgOverlay']) && $style['bgOverlay'] !== '') {
            if (!is_string($style['bgOverlay']) || !preg_match('/^[a-zA-Z0-9#(),.%\s]{0,64}$/', $style['bgOverlay'])) self::error($errors, "$path.bgOverlay", 'color', 'Overlay must be a plain CSS color.');
        }
        if (isset($style['bgOpacity']) && (!is_int($style['bgOpacity']) || $style['bgOpacity'] < 0 || $style['bgOpacity'] > 100)) self::error($errors, "$path.bgOpacity", 'range', 'Opacity must be an integer from 0 to 100.');
        foreach (['paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom', 'borderRadius'] as $spacingKey) {
            if (!isset($style[$spacingKey]) || $style[$spacingKey] === '') continue;
            if (!is_int($style[$spacingKey]) || $style[$spacingKey] < -500 || $style[$spacingKey] > 500) self::error($errors, "$path.$spacingKey", 'range', 'Spacing value must be an integer from -500 to 500.');
        }
        if (isset($style['maxWidth']) && $style['maxWidth'] !== '') {
            if (!is_string($style['maxWidth']) || !preg_match('/^[0-9]{1,5}(\.[0-9]{1,2})?(px|%|vw|em|rem)?$/', $style['maxWidth'])) self::error($errors, "$path.maxWidth", 'pattern', 'Max width must be a plain CSS length.');
        }
        if (isset($style['customClass']) && (!is_string($style['customClass']) || !preg_match('/^[A-Za-z0-9_ -]{0,120}$/', $style['customClass']))) self::error($errors, "$path.customClass", 'class_name', 'Custom class is invalid.');
        foreach (['hideDesktop', 'hideTablet', 'hideMobile'] as $visKey) {
            if (isset($style[$visKey]) && !is_bool($style[$visKey])) self::error($errors, "$path.$visKey", 'type', 'Visibility flag must be a boolean.');
        }
        if (isset($style['cssId']) && $style['cssId'] !== '') {
            if (!is_string($style['cssId']) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $style['cssId'])) {
                self::error($errors, "$path.cssId", 'pattern', 'CSS ID must start with a letter and contain only letters, numbers, hyphens, and underscores.');
            }
        }
        if (isset($style['zIndex']) && $style['zIndex'] !== '') {
            if (!is_int($style['zIndex']) || $style['zIndex'] < -999 || $style['zIndex'] > 9999) self::error($errors, "$path.zIndex", 'range', 'Z-index must be an integer from -999 to 9999.');
        }
    }

    /** @param mixed $value @param list<mixed> $allowed @param list<array<string,mixed>> $errors */
    private static function validateResponsiveEnum(mixed $value, string $path, array $allowed, array &$errors): void
    {
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            if ($value === []) self::error($errors, $path, 'empty', 'Responsive value cannot be empty.');
            foreach ($value as $bp => $v) {
                if (!in_array($bp, ['base', 'sm', 'md', 'lg'], true)) self::error($errors, "$path.$bp", 'breakpoint', 'Unknown responsive breakpoint.');
                elseif (!in_array($v, $allowed, true)) self::error($errors, "$path.$bp", 'enum', 'Invalid responsive value.');
            }
            return;
        }
        if (!in_array($value, $allowed, true)) self::error($errors, $path, 'enum', 'Invalid value.');
    }

    /** @param mixed $value @param list<array<string,mixed>> $errors */
    private static function validateAlias(mixed $value, string $path, array &$errors): void
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $value)) self::error($errors, $path, 'alias', 'Global block alias is invalid.');
    }

    /** @param mixed $value @param list<array<string,mixed>> $errors */
    private static function stringPattern(mixed $value, string $path, string $pattern, array &$errors): void
    {
        if (!is_string($value) || !preg_match($pattern, $value)) self::error($errors, $path, 'pattern', 'Value has an invalid format.');
    }

    /** @param list<array<string,mixed>> $errors */
    private static function error(array &$errors, string $path, string $code, string $message): void { $errors[] = self::issue($path, $code, $message); }

    /** @return array{path:string,code:string,message:string} */
    private static function issue(string $path, string $code, string $message): array { return ['path' => $path, 'code' => $code, 'message' => $message]; }

    private static function result(?array $document, array $errors, array $warnings): array
    {
        return ['valid' => $errors === [], 'document' => $errors === [] ? $document : null, 'errors' => array_values($errors), 'warnings' => array_values($warnings)];
    }
}
