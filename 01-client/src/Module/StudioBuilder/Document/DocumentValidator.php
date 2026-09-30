<?php
/**
 * Kohevo Studio (studio-builder) — Strict Canonical Document Validator.
 *
 * Enforces all Phase 2 validation invariants on the Kohevo Studio Canonical
 * Document (schema_version = "1.0"):
 * - Fails closed on any invalid type, unknown property, or constraint violation.
 * - Rejects `tenant_id` anywhere in the document tree.
 * - Enforces byte size, JSON depth, section count, total block count, and nesting depth.
 * - Validates every block against `BlockRegistry` and its `FieldSchema`.
 * - Enforces block entitlement, RBAC permission, children capability, symbolic tokens,
 *   safe media references, and declarative bindings.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\Schema\ValidationResult;

final class DocumentValidator
{
    /**
     * Validate a canonical Studio document (passed as raw JSON string or decoded array).
     *
     * Optional callbacks allow `StudioApplicationService` to bind tenant-scoped reference
     * checks (media ownership, global_ref ownership, template ownership) and block-level
     * entitlement/permission checks during validation.
     *
     * @param string|array<string, mixed> $document
     * @param array{
     *   max_bytes?: int,
     *   max_sections?: int,
     *   max_blocks?: int,
     *   max_depth?: int,
     *   media_exists?: callable(int $mediaId): bool,
     *   partial_exists?: callable(string $globalRef): bool,
     *   template_exists?: callable(string $templateKey): bool,
     *   entitlement_check?: callable(string $moduleKey): bool,
     *   permission_check?: callable(string $permissionKey): bool
     * } $options
     */
    public static function validate(
        string|array $document,
        BlockRegistry $registry,
        array $options = [],
    ): ValidationResult {
        $maxBytes    = (int) ($options['max_bytes'] ?? CanonicalDocumentSchema::MAX_DOCUMENT_BYTES);
        $maxSections = (int) ($options['max_sections'] ?? CanonicalDocumentSchema::MAX_SECTIONS);
        $maxBlocks   = (int) ($options['max_blocks'] ?? CanonicalDocumentSchema::MAX_BLOCKS_PER_DOCUMENT);
        $maxDepth    = (int) ($options['max_depth'] ?? CanonicalDocumentSchema::MAX_NESTING_DEPTH);

        if (is_string($document)) {
            try {
                $decoded = CanonicalJson::decode($document, $maxBytes, CanonicalDocumentSchema::MAX_JSON_DEPTH);
            } catch (StudioValidationException $e) {
                return ValidationResult::fromErrors($e->errors());
            }
        } else {
            if ($document === [] || array_is_list($document)) {
                return ValidationResult::fromErrors([
                    ValidationResult::issue('$', 'invalid_root_type', 'Canonical Studio document root must be a non-empty JSON object.'),
                ]);
            }
            try {
                $encoded = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return ValidationResult::fromErrors([
                    ValidationResult::issue('$', 'invalid_json', 'Document array cannot be serialized to JSON: ' . $e->getMessage()),
                ]);
            }
            if (strlen($encoded) > $maxBytes) {
                return ValidationResult::fromErrors([
                    ValidationResult::issue('$', 'document_too_large', "Document exceeds maximum allowed size of {$maxBytes} bytes."),
                ]);
            }
            $decoded = $document;
        }

        $errors = [];

        // 1. Recursive check for forbidden `tenant_id` key anywhere in the document tree
        self::scanForForbiddenTenantKey($decoded, '$', $errors);

        // 2. Top-level unknown keys check
        foreach (array_keys($decoded) as $topKey) {
            $k = (string) $topKey;
            if ($k === 'tenant_id') {
                continue; // Already recorded by scanForForbiddenTenantKey
            }
            if (!in_array($k, CanonicalDocumentSchema::ALLOWED_TOP_LEVEL_KEYS, true)) {
                $errors[] = ValidationResult::issue("$.{$k}", 'unknown_property', "Unknown top-level document property '{$k}'.");
            }
        }

        // 3. Required top-level keys
        foreach (CanonicalDocumentSchema::ALLOWED_TOP_LEVEL_KEYS as $reqKey) {
            if (!array_key_exists($reqKey, $decoded)) {
                $errors[] = ValidationResult::issue("$.{$reqKey}", 'required_field', "Missing required top-level field '{$reqKey}'.");
            }
        }

        // 4. schema_version must be strictly "1.0"
        $schemaVersion = $decoded['schema_version'] ?? null;
        if (!is_string($schemaVersion) || $schemaVersion !== CanonicalDocumentSchema::SCHEMA_VERSION) {
            $errors[] = ValidationResult::issue(
                '$.schema_version',
                'unsupported_schema_version',
                'Canonical Studio document schema_version must be exactly "' . CanonicalDocumentSchema::SCHEMA_VERSION . '".'
            );
        }

        // 5. document_type
        $docType = $decoded['document_type'] ?? null;
        if (!is_string($docType) || !in_array($docType, CanonicalDocumentSchema::ALLOWED_DOCUMENT_TYPES, true)) {
            $errors[] = ValidationResult::issue(
                '$.document_type',
                'invalid_document_type',
                'Invalid document_type. Allowed: ' . implode(', ', CanonicalDocumentSchema::ALLOWED_DOCUMENT_TYPES) . '.'
            );
        }

        // 6. template_key
        $templateKey = $decoded['template_key'] ?? null;
        if (!is_string($templateKey) || preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $templateKey) !== 1) {
            $errors[] = ValidationResult::issue('$.template_key', 'invalid_template_key', 'template_key must be a valid slug string.');
        } elseif ($templateKey !== 'default' && isset($options['template_exists']) && is_callable($options['template_exists'])) {
            if (!($options['template_exists'])($templateKey)) {
                $errors[] = ValidationResult::issue('$.template_key', 'cross_tenant_or_missing_template', "Template '{$templateKey}' does not exist in the active tenant.");
            }
        }

        // 7. settings
        if (array_key_exists('settings', $decoded)) {
            self::validateSettings($decoded['settings'], $errors);
        }

        // 8. seo
        if (array_key_exists('seo', $decoded)) {
            self::validateSeo($decoded['seo'], $options, $errors);
        }

        // 9. sections & blocks
        $sections = $decoded['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            $errors[] = ValidationResult::issue('$.sections', 'invalid_sections', 'sections must be a sequential JSON array.');
        } else {
            if (count($sections) > $maxSections) {
                $errors[] = ValidationResult::issue('$.sections', 'max_sections_exceeded', "Document exceeds maximum of {$maxSections} sections.");
            }

            $seenIds = [];
            $totalBlocks = 0;
            // Phase 6: live global references are only valid in referencing document types.
            $options['_document_type'] = is_string($docType) ? $docType : '';

            foreach ($sections as $sIdx => $section) {
                self::validateSection(
                    $section,
                    "$.sections[{$sIdx}]",
                    $registry,
                    $options,
                    $seenIds,
                    $totalBlocks,
                    $maxBlocks,
                    $maxDepth,
                    $errors
                );
            }
        }

        return ValidationResult::fromErrors($errors);
    }

    /**
     * Validate or throw `StudioValidationException`.
     *
     * @param string|array<string, mixed> $document
     * @param array<string, mixed> $options
     */
    public static function assertValid(
        string|array $document,
        BlockRegistry $registry,
        array $options = [],
    ): void {
        $result = self::validate($document, $registry, $options);
        if (!$result->isValid()) {
            throw new StudioValidationException($result->errors());
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function scanForForbiddenTenantKey(mixed $node, string $path, array &$errors): void
    {
        if (!is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $idx => $child) {
                self::scanForForbiddenTenantKey($child, "{$path}[{$idx}]", $errors);
            }
            return;
        }
        foreach ($node as $k => $v) {
            $key = (string) $k;
            $childPath = $path === '$' ? "$.{$key}" : "{$path}.{$key}";
            if ($key === 'tenant_id') {
                $errors[] = ValidationResult::issue(
                    $childPath,
                    'forbidden_tenant_id',
                    'Canonical document must never contain tenant_id; tenant scope is resolved exclusively via TenantContext.'
                );
            }
            self::scanForForbiddenTenantKey($v, $childPath, $errors);
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateSettings(mixed $settings, array &$errors): void
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            $errors[] = ValidationResult::issue('$.settings', 'invalid_settings', 'settings must be a JSON object.');
            return;
        }

        foreach (array_keys($settings) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_SETTINGS_KEYS, true)) {
                $errors[] = ValidationResult::issue("$.settings.{$ks}", 'unknown_property', "Unknown settings property '{$ks}'.");
            }
        }

        if (isset($settings['container_width']) && (!is_string($settings['container_width']) || !in_array($settings['container_width'], CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true))) {
            $errors[] = ValidationResult::issue('$.settings.container_width', 'invalid_container_width', 'Invalid settings.container_width value.');
        }

        if (isset($settings['token_group']) && (!is_string($settings['token_group']) || preg_match(CanonicalDocumentSchema::TOKEN_GROUP_PATTERN, $settings['token_group']) !== 1)) {
            $errors[] = ValidationResult::issue('$.settings.token_group', 'invalid_token_group', 'Invalid settings.token_group identifier.');
        }

        foreach (['header_mode', 'footer_mode'] as $chromeKey) {
            if (isset($settings[$chromeKey]) && (!is_string($settings[$chromeKey]) || !in_array($settings[$chromeKey], CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true))) {
                $errors[] = ValidationResult::issue("$.settings.{$chromeKey}", 'invalid_chrome_mode', "Invalid settings.{$chromeKey} value.");
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateSeo(mixed $seo, array $options, array &$errors): void
    {
        if (!is_array($seo) || ($seo !== [] && array_is_list($seo))) {
            $errors[] = ValidationResult::issue('$.seo', 'invalid_seo', 'seo must be a JSON object.');
            return;
        }

        foreach (array_keys($seo) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_SEO_KEYS, true)) {
                $errors[] = ValidationResult::issue("$.seo.{$ks}", 'unknown_property', "Unknown seo property '{$ks}'.");
            }
        }

        if (array_key_exists('title', $seo) && $seo['title'] !== null) {
            if (!is_string($seo['title']) || mb_strlen($seo['title'], 'UTF-8') > 255 || FieldSchema::containsExecutableOrSqlFragment($seo['title'])) {
                $errors[] = ValidationResult::issue('$.seo.title', 'invalid_seo_title', 'seo.title must be a safe string <= 255 chars.');
            }
        }

        if (array_key_exists('description', $seo) && $seo['description'] !== null) {
            if (!is_string($seo['description']) || mb_strlen($seo['description'], 'UTF-8') > 1000 || FieldSchema::containsExecutableOrSqlFragment($seo['description'])) {
                $errors[] = ValidationResult::issue('$.seo.description', 'invalid_seo_description', 'seo.description must be a safe string <= 1000 chars.');
            }
        }

        if (array_key_exists('canonical_url', $seo) && $seo['canonical_url'] !== null) {
            if (!is_string($seo['canonical_url']) || !FieldSchema::isSafeUrl($seo['canonical_url'])) {
                $errors[] = ValidationResult::issue('$.seo.canonical_url', 'invalid_canonical_url', 'seo.canonical_url must be a safe URL.');
            }
        }

        if (array_key_exists('robots', $seo) && $seo['robots'] !== null) {
            if (!is_string($seo['robots']) || !in_array($seo['robots'], CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES, true)) {
                $errors[] = ValidationResult::issue('$.seo.robots', 'invalid_robots', 'seo.robots must be a supported robots directive.');
            }
        }

        if (array_key_exists('og_image_media_id', $seo) && $seo['og_image_media_id'] !== null) {
            $mediaId = $seo['og_image_media_id'];
            if (!is_int($mediaId) || $mediaId <= 0) {
                $errors[] = ValidationResult::issue('$.seo.og_image_media_id', 'invalid_media_id', 'seo.og_image_media_id must be a positive integer or null.');
            } elseif (isset($options['media_exists']) && is_callable($options['media_exists']) && !($options['media_exists'])($mediaId)) {
                $errors[] = ValidationResult::issue('$.seo.og_image_media_id', 'cross_tenant_or_missing_media', "Referenced media_id {$mediaId} does not exist in the active tenant.");
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $seenIds
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateSection(
        mixed $section,
        string $path,
        BlockRegistry $registry,
        array $options,
        array &$seenIds,
        int &$totalBlocks,
        int $maxBlocks,
        int $maxDepth,
        array &$errors,
    ): void {
        if (!is_array($section) || array_is_list($section)) {
            $errors[] = ValidationResult::issue($path, 'invalid_section', 'Section must be a JSON object.');
            return;
        }

        foreach (array_keys($section) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_SECTION_KEYS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown section property '{$ks}'.");
            }
        }

        foreach (CanonicalDocumentSchema::ALLOWED_SECTION_KEYS as $reqKey) {
            if (!array_key_exists($reqKey, $section)) {
                $errors[] = ValidationResult::issue("{$path}.{$reqKey}", 'required_field', "Missing required section field '{$reqKey}'.");
            }
        }

        // Section ID
        $secId = $section['id'] ?? null;
        if (!is_string($secId) || preg_match(CanonicalDocumentSchema::SECTION_ID_PATTERN, $secId) !== 1) {
            $errors[] = ValidationResult::issue("{$path}.id", 'invalid_node_id', 'Section id must match ' . CanonicalDocumentSchema::SECTION_ID_PATTERN . '.');
        } else {
            if (isset($seenIds[$secId])) {
                $errors[] = ValidationResult::issue("{$path}.id", 'duplicate_node_id', "Duplicate node id '{$secId}' (already used at {$seenIds[$secId]}).");
            } else {
                $seenIds[$secId] = "{$path}.id";
            }
        }

        // Section label
        $label = $section['label'] ?? null;
        if (!is_string($label) || mb_strlen($label, 'UTF-8') > 120 || FieldSchema::containsExecutableOrSqlFragment($label)) {
            $errors[] = ValidationResult::issue("{$path}.label", 'invalid_section_label', 'Section label must be a safe string <= 120 chars.');
        }

        // global_ref — a LIVE reference to a Global Component (Phase 6). The
        // section then owns no content of its own: its blocks must be empty and
        // the referenced component is rendered from its published revision. A
        // component / header / footer document may not reference (one level).
        if (array_key_exists('global_ref', $section) && $section['global_ref'] !== null) {
            $gRef = $section['global_ref'];
            if (!is_string($gRef) || preg_match(CanonicalDocumentSchema::GLOBAL_REF_PATTERN, $gRef) !== 1) {
                $errors[] = ValidationResult::issue("{$path}.global_ref", 'invalid_global_ref', 'section.global_ref must be null or a valid symbolic reference key.');
            } elseif (isset($options['partial_exists']) && is_callable($options['partial_exists']) && !($options['partial_exists'])($gRef)) {
                $errors[] = ValidationResult::issue("{$path}.global_ref", 'cross_tenant_or_missing_partial', "Referenced global_ref '{$gRef}' does not exist in the active tenant.");
            }
            $docType = (string) ($options['_document_type'] ?? '');
            if ($docType !== '' && !in_array($docType, CanonicalDocumentSchema::GLOBAL_REF_DOCUMENT_TYPES, true)) {
                $errors[] = ValidationResult::issue("{$path}.global_ref", 'global_ref_not_allowed', "A '{$docType}' document cannot reference a global component (references are one level deep).");
            }
            $refBlocks = $section['blocks'] ?? null;
            if (is_array($refBlocks) && $refBlocks !== []) {
                $errors[] = ValidationResult::issue("{$path}.blocks", 'global_ref_owns_no_blocks', 'A section that references a global component must not carry local blocks.');
            }
        }

        // layout
        if (array_key_exists('layout', $section)) {
            self::validateSectionLayout($section['layout'], "{$path}.layout", $errors);
        }

        // visibility
        if (array_key_exists('visibility', $section)) {
            self::validateVisibility($section['visibility'], "{$path}.visibility", $errors);
        }

        // blocks
        $blocks = $section['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks)) {
            $errors[] = ValidationResult::issue("{$path}.blocks", 'invalid_blocks', 'section.blocks must be a sequential JSON array.');
            return;
        }

        foreach ($blocks as $bIdx => $block) {
            self::validateBlock(
                $block,
                "{$path}.blocks[{$bIdx}]",
                1,
                $registry,
                $options,
                $seenIds,
                $totalBlocks,
                $maxBlocks,
                $maxDepth,
                $errors
            );
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateSectionLayout(mixed $layout, string $path, array &$errors): void
    {
        if (!is_array($layout) || ($layout !== [] && array_is_list($layout))) {
            $errors[] = ValidationResult::issue($path, 'invalid_layout', 'section.layout must be a JSON object.');
            return;
        }

        foreach (array_keys($layout) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_LAYOUT_KEYS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown section layout property '{$ks}'.");
            }
        }

        if (isset($layout['columns'])) {
            self::validateResponsiveColumns($layout['columns'], "{$path}.columns", $errors);
        }

        if (isset($layout['width']) && (!is_string($layout['width']) || !in_array($layout['width'], CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true))) {
            $errors[] = ValidationResult::issue("{$path}.width", 'invalid_layout_width', 'Invalid section layout width.');
        }

        if (isset($layout['gap']) && (!is_string($layout['gap']) || !in_array($layout['gap'], CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true))) {
            $errors[] = ValidationResult::issue("{$path}.gap", 'invalid_layout_gap', 'Invalid section layout gap token.');
        }

        if (isset($layout['padding_y'])) {
            self::validateResponsiveSpacing($layout['padding_y'], "{$path}.padding_y", $errors);
        }

        if (array_key_exists('background_token', $layout) && $layout['background_token'] !== null) {
            if (!is_string($layout['background_token']) || preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $layout['background_token']) !== 1) {
                $errors[] = ValidationResult::issue("{$path}.background_token", 'invalid_token_ref', 'section.layout.background_token must be a valid symbolic design token.');
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateResponsiveColumns(mixed $columns, string $path, array &$errors): void
    {
        if (is_int($columns)) {
            if ($columns < 1 || $columns > 12) {
                $errors[] = ValidationResult::issue($path, 'invalid_columns', 'Columns must be between 1 and 12.');
            }
            return;
        }
        if (!is_array($columns) || $columns === [] || array_is_list($columns)) {
            $errors[] = ValidationResult::issue($path, 'invalid_columns', 'Columns must be an integer (1..12) or breakpoint map.');
            return;
        }
        foreach ($columns as $bp => $cols) {
            if (!in_array((string) $bp, CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$bp}", 'invalid_breakpoint', "Unknown breakpoint '{$bp}'.");
                continue;
            }
            if (!is_int($cols) || $cols < 1 || $cols > 12) {
                $errors[] = ValidationResult::issue("{$path}.{$bp}", 'invalid_columns', 'Breakpoint columns must be an integer between 1 and 12.');
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateResponsiveSpacing(mixed $spacing, string $path, array &$errors): void
    {
        if (is_string($spacing)) {
            if (!in_array($spacing, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
                $errors[] = ValidationResult::issue($path, 'invalid_spacing', 'Spacing value must be a valid spacing scale token.');
            }
            return;
        }
        if (!is_array($spacing) || $spacing === [] || array_is_list($spacing)) {
            $errors[] = ValidationResult::issue($path, 'invalid_spacing', 'Spacing must be a scale token or breakpoint map.');
            return;
        }
        foreach ($spacing as $bp => $val) {
            if (!in_array((string) $bp, CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$bp}", 'invalid_breakpoint', "Unknown breakpoint '{$bp}'.");
                continue;
            }
            if (!is_string($val) || !in_array($val, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$bp}", 'invalid_spacing', 'Breakpoint spacing must be a valid spacing scale token.');
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateVisibility(mixed $visibility, string $path, array &$errors): void
    {
        if (!is_array($visibility) || ($visibility !== [] && array_is_list($visibility))) {
            $errors[] = ValidationResult::issue($path, 'invalid_visibility', 'visibility must be a JSON object.');
            return;
        }

        foreach (array_keys($visibility) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_VISIBILITY_KEYS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown visibility property '{$ks}'.");
            }
        }

        if (isset($visibility['devices'])) {
            $devices = $visibility['devices'];
            if (!is_array($devices) || !array_is_list($devices) || $devices === []) {
                $errors[] = ValidationResult::issue("{$path}.devices", 'invalid_visibility_devices', 'visibility.devices must be a non-empty list of breakpoints.');
            } else {
                foreach ($devices as $dIdx => $dev) {
                    if (!is_string($dev) || !in_array($dev, CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, true)) {
                        $errors[] = ValidationResult::issue("{$path}.devices[{$dIdx}]", 'invalid_breakpoint', 'Invalid device breakpoint in visibility.devices.');
                    }
                }
            }
        }

        if (isset($visibility['auth_state'])) {
            if (!is_string($visibility['auth_state']) || !in_array($visibility['auth_state'], CanonicalDocumentSchema::ALLOWED_AUTH_STATES, true)) {
                $errors[] = ValidationResult::issue("{$path}.auth_state", 'invalid_auth_state', 'visibility.auth_state must be any, authenticated, or guest.');
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $seenIds
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateBlock(
        mixed $block,
        string $path,
        int $depth,
        BlockRegistry $registry,
        array $options,
        array &$seenIds,
        int &$totalBlocks,
        int $maxBlocks,
        int $maxDepth,
        array &$errors,
    ): void {
        if ($depth > $maxDepth) {
            $errors[] = ValidationResult::issue($path, 'max_nesting_depth_exceeded', "Block nesting depth exceeds maximum allowed depth of {$maxDepth}.");
            return;
        }

        $totalBlocks++;
        if ($totalBlocks > $maxBlocks) {
            $errors[] = ValidationResult::issue($path, 'max_blocks_exceeded', "Document exceeds maximum of {$maxBlocks} total blocks.");
            return;
        }

        if (!is_array($block) || array_is_list($block)) {
            $errors[] = ValidationResult::issue($path, 'invalid_block', 'Block must be a JSON object.');
            return;
        }

        foreach (array_keys($block) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_BLOCK_KEYS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown block property '{$ks}'.");
            }
        }

        foreach (CanonicalDocumentSchema::ALLOWED_BLOCK_KEYS as $reqKey) {
            if (!array_key_exists($reqKey, $block)) {
                $errors[] = ValidationResult::issue("{$path}.{$reqKey}", 'required_field', "Missing required block field '{$reqKey}'.");
            }
        }

        // Block ID
        $blockId = $block['id'] ?? null;
        if (!is_string($blockId) || preg_match(CanonicalDocumentSchema::BLOCK_ID_PATTERN, $blockId) !== 1) {
            $errors[] = ValidationResult::issue("{$path}.id", 'invalid_node_id', 'Block id must match ' . CanonicalDocumentSchema::BLOCK_ID_PATTERN . '.');
        } else {
            if (isset($seenIds[$blockId])) {
                $errors[] = ValidationResult::issue("{$path}.id", 'duplicate_node_id', "Duplicate node id '{$blockId}' (already used at {$seenIds[$blockId]}).");
            } else {
                $seenIds[$blockId] = "{$path}.id";
            }
        }

        // Block type & registry lookup
        $type = $block['type'] ?? null;
        if (!is_string($type) || preg_match(CanonicalDocumentSchema::BLOCK_TYPE_PATTERN, $type) !== 1) {
            $errors[] = ValidationResult::issue("{$path}.type", 'invalid_block_type', 'Block type must be a valid namespaced identifier.');
            return;
        }

        $definition = $registry->get($type);
        if ($definition === null) {
            $errors[] = ValidationResult::issue("{$path}.type", 'unknown_block_type', "Unknown or unregistered Studio block type '{$type}'.");
            return;
        }

        // Block entitlement & permission checks if callbacks provided
        $reqEntitlement = $definition->requiredEntitlement();
        if ($reqEntitlement !== null && isset($options['entitlement_check']) && is_callable($options['entitlement_check'])) {
            if (!($options['entitlement_check'])($reqEntitlement)) {
                $errors[] = ValidationResult::issue("{$path}.type", 'block_module_not_entitled', "Block '{$type}' requires commercial entitlement '{$reqEntitlement}'.");
            }
        }
        $reqPerm = $definition->requiredPermission();
        if (isset($options['permission_check']) && is_callable($options['permission_check'])) {
            if (!($options['permission_check'])($reqPerm)) {
                $errors[] = ValidationResult::issue("{$path}.type", 'block_permission_denied', "Block '{$type}' requires permission '{$reqPerm}'.");
            }
        }

        // Block version
        $version = $block['version'] ?? null;
        if (!is_int($version) || $version !== $definition->version()) {
            $errors[] = ValidationResult::issue(
                "{$path}.version",
                'invalid_block_version',
                "Block '{$type}' version must be integer {$definition->version()}."
            );
        }

        // Block props via FieldSchema
        $props = $block['props'] ?? null;
        if (!is_array($props) || ($props !== [] && array_is_list($props))) {
            $errors[] = ValidationResult::issue("{$path}.props", 'invalid_props', 'block.props must be a JSON object.');
        } else {
            $propResult = $definition->validateProps($props, "{$path}.props");
            foreach ($propResult->errors() as $err) {
                $errors[] = $err;
            }
            // Verify tenant-scoped media_id references in props if media_exists callback is provided
            if (isset($options['media_exists']) && is_callable($options['media_exists'])) {
                self::validateMediaReferencesInProps($definition->schema()->fields(), $props, "{$path}.props", $options['media_exists'], $errors);
            }
        }

        // Block style
        if (array_key_exists('style', $block)) {
            self::validateBlockStyle($block['style'], $definition, "{$path}.style", $errors);
        }

        // Block visibility
        if (array_key_exists('visibility', $block)) {
            self::validateVisibility($block['visibility'], "{$path}.visibility", $errors);
        }

        // Block bindings
        if (array_key_exists('bindings', $block)) {
            self::validateBindings($block['bindings'], $definition, "{$path}.bindings", $errors);
        }

        // Block children
        $children = $block['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            $errors[] = ValidationResult::issue("{$path}.children", 'invalid_children', 'block.children must be a sequential JSON array.');
            return;
        }

        if ($children !== [] && !$definition->allowsChildren()) {
            $errors[] = ValidationResult::issue("{$path}.children", 'children_not_allowed', "Block type '{$type}' does not allow nested children.");
            return;
        }

        $allowedChildTypes = $definition->allowedChildTypes();
        foreach ($children as $cIdx => $child) {
            $childPath = "{$path}.children[{$cIdx}]";
            if ($allowedChildTypes !== [] && is_array($child) && isset($child['type']) && is_string($child['type'])) {
                if (!in_array($child['type'], $allowedChildTypes, true)) {
                    $errors[] = ValidationResult::issue("{$childPath}.type", 'disallowed_child_type', "Block '{$type}' does not allow child block of type '{$child['type']}'.");
                }
            }
            self::validateBlock(
                $child,
                $childPath,
                $depth + 1,
                $registry,
                $options,
                $seenIds,
                $totalBlocks,
                $maxBlocks,
                $maxDepth,
                $errors
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $props
     * @param callable(int): bool $mediaExists
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateMediaReferencesInProps(
        array $fields,
        array $props,
        string $basePath,
        callable $mediaExists,
        array &$errors,
    ): void {
        foreach ($fields as $field) {
            $key  = (string) $field['key'];
            $type = (string) $field['type'];
            $val  = $props[$key] ?? null;
            if ($val === null) {
                continue;
            }
            $fieldPath = "{$basePath}.{$key}";

            if ($type === 'media_ref' && is_array($val) && isset($val['media_id']) && is_int($val['media_id']) && $val['media_id'] > 0) {
                if (!$mediaExists($val['media_id'])) {
                    $errors[] = ValidationResult::issue(
                        "{$fieldPath}.media_id",
                        'cross_tenant_or_missing_media',
                        "Referenced media_id {$val['media_id']} does not exist in the active tenant."
                    );
                }
            } elseif ($type === 'repeater' && is_array($val) && ($field['item_schema'] ?? null) instanceof FieldSchema) {
                /** @var FieldSchema $itemSchema */
                $itemSchema = $field['item_schema'];
                foreach ($val as $idx => $item) {
                    if (is_array($item)) {
                        self::validateMediaReferencesInProps($itemSchema->fields(), $item, "{$fieldPath}[{$idx}]", $mediaExists, $errors);
                    }
                }
            } elseif ($type === 'object' && is_array($val) && ($field['properties'] ?? null) instanceof FieldSchema) {
                /** @var FieldSchema $propSchema */
                $propSchema = $field['properties'];
                self::validateMediaReferencesInProps($propSchema->fields(), $val, $fieldPath, $mediaExists, $errors);
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateBlockStyle(
        mixed $style,
        BlockDefinitionInterface $definition,
        string $path,
        array &$errors,
    ): void {
        if (!is_array($style) || ($style !== [] && array_is_list($style))) {
            $errors[] = ValidationResult::issue($path, 'invalid_style', 'block.style must be a JSON object.');
            return;
        }

        $allowedCapabilities = $definition->styleCapabilities();

        foreach (array_keys($style) as $k) {
            $ks = (string) $k;
            if ($ks === 'tenant_id') {
                continue;
            }
            if (!in_array($ks, CanonicalDocumentSchema::ALLOWED_STYLE_KEYS, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unknown_property', "Unknown block style property '{$ks}'. Allows symbolic tokens only.");
                continue;
            }
            if (!in_array($ks, $allowedCapabilities, true)) {
                $errors[] = ValidationResult::issue("{$path}.{$ks}", 'unsupported_style_capability', "Block '{$definition->type()}' does not support style property '{$ks}'.");
            }
        }

        if (array_key_exists('align', $style) && $style['align'] !== null) {
            $align = $style['align'];
            if (is_string($align)) {
                if (!in_array($align, CanonicalDocumentSchema::ALLOWED_ALIGNMENTS, true)) {
                    $errors[] = ValidationResult::issue("{$path}.align", 'invalid_align', 'Invalid alignment value.');
                }
            } elseif (is_array($align) && $align !== [] && !array_is_list($align)) {
                foreach ($align as $bp => $aVal) {
                    if (!in_array((string) $bp, CanonicalDocumentSchema::ALLOWED_BREAKPOINTS, true)) {
                        $errors[] = ValidationResult::issue("{$path}.align.{$bp}", 'invalid_breakpoint', "Unknown breakpoint '{$bp}'.");
                    } elseif (!is_string($aVal) || !in_array($aVal, CanonicalDocumentSchema::ALLOWED_ALIGNMENTS, true)) {
                        $errors[] = ValidationResult::issue("{$path}.align.{$bp}", 'invalid_align', 'Invalid breakpoint alignment value.');
                    }
                }
            } else {
                $errors[] = ValidationResult::issue("{$path}.align", 'invalid_align', 'style.align must be an alignment token or breakpoint map.');
            }
        }

        foreach (['surface_token', 'text_token', 'spacing_token', 'radius_token', 'shadow_token', 'font_token'] as $tokenField) {
            if (array_key_exists($tokenField, $style) && $style[$tokenField] !== null) {
                $tokenVal = $style[$tokenField];
                if (!is_string($tokenVal) || preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $tokenVal) !== 1) {
                    $errors[] = ValidationResult::issue(
                        "{$path}.{$tokenField}",
                        'invalid_token_ref',
                        "style.{$tokenField} must be a symbolic design token reference (arbitrary CSS is forbidden)."
                    );
                }
            }
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function validateBindings(
        mixed $bindings,
        BlockDefinitionInterface $definition,
        string $path,
        array &$errors,
    ): void {
        if (!is_array($bindings) || ($bindings !== [] && array_is_list($bindings))) {
            $errors[] = ValidationResult::issue($path, 'invalid_bindings', 'block.bindings must be a JSON object.');
            return;
        }

        if ($bindings === []) {
            return;
        }

        $allowedProviders = $definition->allowedBindingProviders();
        if ($allowedProviders === []) {
            $errors[] = ValidationResult::issue($path, 'bindings_not_supported', "Block type '{$definition->type()}' does not support dynamic data bindings.");
            return;
        }

        foreach ($bindings as $slotKey => $binding) {
            $slot = (string) $slotKey;
            if ($slot === 'tenant_id') {
                continue;
            }
            $slotPath = "{$path}.{$slot}";
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $slot)) {
                $errors[] = ValidationResult::issue($slotPath, 'invalid_binding_slot', "Invalid binding slot key '{$slot}'.");
                continue;
            }
            if (!is_array($binding) || array_is_list($binding)) {
                $errors[] = ValidationResult::issue($slotPath, 'invalid_binding_descriptor', 'Binding descriptor must be an object with {provider, params?, mapping?}.');
                continue;
            }

            $allowedDescKeys = ['provider', 'params', 'mapping'];
            foreach (array_keys($binding) as $bk) {
                $bks = (string) $bk;
                if ($bks === 'tenant_id') {
                    continue;
                }
                if (!in_array($bks, $allowedDescKeys, true)) {
                    $errors[] = ValidationResult::issue("{$slotPath}.{$bks}", 'unknown_property', "Unknown binding property '{$bks}'.");
                }
            }

            $provider = $binding['provider'] ?? null;
            if (!is_string($provider) || preg_match(CanonicalDocumentSchema::PROVIDER_KEY_PATTERN, $provider) !== 1) {
                $errors[] = ValidationResult::issue("{$slotPath}.provider", 'invalid_binding_provider', 'Binding provider must be a valid namespaced key.');
            } elseif (!in_array($provider, $allowedProviders, true)) {
                $errors[] = ValidationResult::issue(
                    "{$slotPath}.provider",
                    'disallowed_binding_provider',
                    "Binding provider '{$provider}' is not allowed on block '{$definition->type()}'."
                );
            }

            if (array_key_exists('params', $binding)) {
                $params = $binding['params'];
                if (!is_array($params) || ($params !== [] && array_is_list($params))) {
                    $errors[] = ValidationResult::issue("{$slotPath}.params", 'invalid_binding_params', 'binding.params must be a JSON object.');
                } else {
                    foreach ($params as $pk => $pv) {
                        $pks = (string) $pk;
                        if ($pks === 'tenant_id') {
                            continue;
                        }
                        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $pks)) {
                            $errors[] = ValidationResult::issue("{$slotPath}.params.{$pks}", 'invalid_param_key', "Invalid binding parameter key '{$pks}'.");
                        }
                        if (!is_string($pv) && !is_int($pv) && !is_float($pv) && !is_bool($pv)) {
                            $errors[] = ValidationResult::issue("{$slotPath}.params.{$pks}", 'invalid_param_value', 'Binding parameter value must be a scalar.');
                        } elseif (is_string($pv) && (mb_strlen($pv, 'UTF-8') > 255 || FieldSchema::containsExecutableOrSqlFragment($pv))) {
                            $errors[] = ValidationResult::issue("{$slotPath}.params.{$pks}", 'unsafe_param_value', 'Binding parameter value contains unsafe or oversized content.');
                        }
                    }
                }
            }

            if (array_key_exists('mapping', $binding)) {
                $mapping = $binding['mapping'];
                if (!is_array($mapping) || ($mapping !== [] && array_is_list($mapping))) {
                    $errors[] = ValidationResult::issue("{$slotPath}.mapping", 'invalid_binding_mapping', 'binding.mapping must be a JSON object.');
                } else {
                    foreach ($mapping as $mk => $mv) {
                        $mks = (string) $mk;
                        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $mks) || !is_string($mv) || !preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $mv)) {
                            $errors[] = ValidationResult::issue("{$slotPath}.mapping.{$mks}", 'invalid_mapping_entry', 'Binding mapping must map valid prop names to safe provider field names.');
                        }
                    }
                }
            }
        }
    }
}
