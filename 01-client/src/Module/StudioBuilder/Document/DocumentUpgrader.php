<?php
/**
 * Kohevo Studio (studio-builder) — Document Upgrader.
 *
 * Two steps, in this order. structure() turns a section-based "1.0" document into
 * the Container-only format (docs/STUDIO-CONTAINER-ONLY.md); it needs no registry
 * and leaves a current document alone. upgrade() then brings the blocks of a document saved by an older Studio up to the block
 * versions this site registers, BEFORE the document is validated, so raising
 * a block's version does not make earlier content unusable. Called on every
 * read of stored or imported content:
 *   - package import, and package export (exports carry current versions);
 *   - template apply/insert, and detaching a global component;
 *   - the editor's load and every edit (StudioApplicationService), publish
 *     and rollback (StudioRevisionService);
 *   - rendering (RenderDocumentPreparer), for published pages, previews,
 *     partials and templates.
 * Upgrades happen in memory: a stored revision is never rewritten. The next
 * draft, publish or rollback writes a NEW revision at the current versions,
 * and the compilation fingerprint (ModuleBlockDefinitions::registryFingerprint,
 * type@version) makes every page recompile after a bump.
 *
 * - Every block, at any depth, whose integer `version`
 *   is below its definition's version is stepped up one version at a time
 *   through `BlockDefinitionInterface::upgrade()`.
 * - All or nothing per block: when a step is missing, the block is left
 *   exactly as it was, and the validator then refuses it with
 *   `invalid_block_version` (naming the block, its version and the
 *   supported one).
 * - Unknown types, newer versions and malformed blocks are left alone for
 *   the validator to report. Nothing else in the document is touched.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class DocumentUpgrader
{
    /**
     * @param array<string, mixed> $document
     * @return array{document: array<string, mixed>, upgraded: list<array{path: string, type: string, from: int, to: int}>}
     */
    public static function upgrade(array $document, BlockRegistry $registry): array
    {
        $document = self::structure($document);
        $upgraded = [];
        if (is_array($document['containers'] ?? null)) {
            $document['containers'] = self::blocks($document['containers'], $registry, '$.containers', $upgraded);
        }
        return ['document' => $document, 'upgraded' => $upgraded];
    }

    /**
     * A section-based document, in the Container-only format. A current document comes back as it was, so this is safe
     * to call on anything read from storage or received from outside. Malformed entries are carried over for the
     * validator to report.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function structure(array $document): array
    {
        if (is_array($document['containers'] ?? null)) {
            $document['containers'] = self::layoutSections($document['containers']);
        }
        if (is_string($document['document_type'] ?? null)) {
            $document['document_type'] = self::typeName($document['document_type']);
        }
        if (!array_key_exists('sections', $document) || array_key_exists('containers', $document)) {
            return $document;
        }
        $sections = $document['sections'];
        unset($document['sections']);
        $document['schema_version'] = CanonicalDocumentSchema::SCHEMA_VERSION;
        $document['containers'] = is_array($sections) && array_is_list($sections)
            ? array_map(static fn (mixed $section): mixed => is_array($section) ? self::container($section) : $section, $sections)
            : $sections;
        if (is_array($document['containers'])) {
            $document['containers'] = self::layoutSections($document['containers']);
        }
        return $document;
    }

    /**
     * A page, document or template type under its current name, for anything read from outside the database: a package,
     * a backup. (StudioSchemaManager moves the stored rows.)
     */
    public static function typeName(string $type): string
    {
        return $type === 'section_preset' ? CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE : $type;
    }

    /**
     * A template's stored section, as a Container.
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function sectionToContainer(array $section): array
    {
        return self::structure(['sections' => [$section]])['containers'][0];
    }

    /** A section's width, in the Site settings' names. */
    private const SIZES = ['narrow', 'normal', 'wide', 'full'];

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private static function container(array $section): array
    {
        $id = $section['id'] ?? null;
        $container = [
            'id'         => is_string($id) ? (string) preg_replace('/^sec_/', 'blk_', $id) : $id,
            'type'       => CanonicalDocumentSchema::CONTAINER_TYPE,
            'version'    => CanonicalDocumentSchema::CONTAINER_VERSION,
            'tag'        => 'section',
            'props'      => [],
            'style'      => [],
            'visibility' => is_array($section['visibility'] ?? null) ? $section['visibility'] : CanonicalDocumentSchema::defaultVisibility(),
            'bindings'   => [],
            'children'   => is_array($section['blocks'] ?? null) ? $section['blocks'] : [],
            // Site CSS written for sections keeps finding them.
            'classNames' => ['sb-section'],
        ];
        $metadata = [];
        if (is_string($section['label'] ?? null) && $section['label'] !== '') {
            $metadata['label'] = mb_substr($section['label'], 0, CanonicalDocumentSchema::BLOCK_LABEL_MAX_LENGTH, 'UTF-8');
        }
        if (($section['locked'] ?? null) === true) {
            $metadata['locked'] = true;
        }
        if ($metadata !== []) {
            $container['metadata'] = $metadata;
        }
        foreach (['animation', 'interactions'] as $key) {
            if (is_array($section[$key] ?? null) && $section[$key] !== []) {
                $container[$key] = $section[$key];
            }
        }
        if (is_string($section['global_ref'] ?? null) && $section['global_ref'] !== '') {
            $container['global_ref'] = $section['global_ref'];
            return $container; // a reference keeps its visibility; the component brings the rest
        }

        $layout = is_array($section['layout'] ?? null) ? $section['layout'] : [];
        $layout += ['background_token' => 'surface.primary', 'columns' => ['base' => 1, 'md' => 12], 'gap' => 'md', 'padding_y' => ['base' => 'md', 'md' => 'lg'], 'width' => 'default'];
        $style = is_array($section['style'] ?? null) ? $section['style'] : [];
        if (is_string($layout['background_token'])) {
            $style['surface_token'] = $layout['background_token'];
        }
        if (is_string($layout['gap'])) {
            $container['props']['gap'] = $layout['gap'];
        }

        $style['layout'] = ['content_width' => 'boxed', 'gutter' => 'site', 'display' => 'grid'];
        $responsive = [];
        self::perDevice($style, $responsive, 'columns', self::devices($layout['columns'], static function (mixed $n) use ($container): ?int {
            return is_int($n) && $n >= 1 && $n <= 12 ? min($n, max(1, count($container['children']))) : null;
        }));
        self::perDevice($style, $responsive, 'padding_y', self::devices($layout['padding_y'], static fn (mixed $v): ?string => is_string($v) ? $v : null));

        // A section's padding in the Inspector beat its spacing on every device; so it still does (padding comes later in the rule).
        if (is_array($style['padding'] ?? null) && isset($style['padding']['top'], $style['padding']['bottom'])) {
            unset($style['layout']['padding_y']);
            foreach (array_keys($responsive) as $device) {
                unset($responsive[$device]['style']['layout']['padding_y']);
            }
        }

        $width = $layout['width'];
        if (is_string($width) && in_array($width, self::SIZES, true)) {
            $style['layout']['content_size'] = $width;
        } elseif ($width === 'custom' && is_array($layout['custom_width'] ?? null)) {
            self::perDevice($style, $responsive, 'content_max', self::devices($layout['custom_width'], static fn (mixed $v): ?string => is_string($v) ? $v : null));
        }
        if (is_array($style['border'] ?? null)) {
            $style['border'] = self::border($style['border'], $style);
        }

        $container['style'] = $style;
        $responsive = array_filter($responsive, static fn (array $d): bool => array_filter($d['style']['layout'] ?? []) !== []);
        if ($responsive !== []) {
            $container['responsive'] = $responsive;
        }
        return $container;
    }

    /**
     * A section's border drew a solid line from a width alone and could take a colour token; a block's needs both spelled out.
     *
     * @param array<string, mixed> $border
     * @param array<string, mixed> $style
     * @return array<string, mixed>
     */
    private static function border(array $border, array &$style): array
    {
        if (isset($border['width']) && !isset($border['style'])) {
            $border['style'] = 'solid';
        }
        if (is_string($border['color'] ?? null) && preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $border['color']) === 1) {
            $style['border_token'] = $border['color'];
            unset($border['color']);
        }
        return $border;
    }

    /**
     * A section's mobile-first breakpoint map, as the Container's desktop-first devices. An `sm` value has no device of
     * its own, so it counts from tablet up, and a phone keeps `base`.
     *
     * @return array{desktop: mixed, tablet: mixed, mobile: mixed}
     */
    private static function devices(mixed $value, callable $read): array
    {
        $map = is_array($value) ? $value : ['base' => $value];
        $at = static function (array $keys) use ($map, $read): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $map) && ($v = $read($map[$key])) !== null) {
                    return $v;
                }
            }
            return null;
        };
        return ['desktop' => $at(['lg', 'md', 'sm', 'base']), 'tablet' => $at(['md', 'sm', 'base']), 'mobile' => $at(['base'])];
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, array<string, mixed>> $responsive
     * @param array{desktop: mixed, tablet: mixed, mobile: mixed} $values
     */
    private static function perDevice(array &$style, array &$responsive, string $field, array $values): void
    {
        if ($values['desktop'] !== null) {
            $style['layout'][$field] = $values['desktop'];
        }
        if ($values['tablet'] !== null && $values['tablet'] !== $values['desktop']) {
            $responsive['tablet']['style']['layout'][$field] = $values['tablet'];
        }
        $above = $values['tablet'] ?? $values['desktop'];
        if ($values['mobile'] !== null && $values['mobile'] !== $above) {
            $responsive['mobile']['style']['layout'][$field] = $values['mobile'];
        }
    }

    /** Widths of the retired `layout.section` block. */
    private const SECTION_BLOCK_WIDTHS = ['boxed' => 'wide', 'narrow' => 'narrow', 'full' => 'full'];
    private const SECTION_BLOCK_HEIGHTS = ['screen' => '100vh', 'half_screen' => '50vh', 'sm' => '16rem', 'md' => '24rem', 'lg' => '36rem', 'xl' => '48rem'];

    /**
     * The retired `layout.section` block is a Container too, at any depth.
     *
     * @param array<mixed> $blocks
     * @return array<mixed>
     */
    private static function layoutSections(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            if (is_array($block['children'] ?? null)) {
                $block['children'] = self::layoutSections($block['children']);
            }
            if (($block['type'] ?? null) === 'layout.section') {
                $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                $style = is_array($block['style'] ?? null) ? $block['style'] : [];
                $style['layout'] = (is_array($style['layout'] ?? null) ? $style['layout'] : []) + [
                    'content_width' => 'boxed',
                    'content_size'  => self::SECTION_BLOCK_WIDTHS[$props['content_width'] ?? 'boxed'] ?? 'wide',
                    'padding_y'     => is_string($props['padding_y'] ?? null) ? $props['padding_y'] : 'md',
                ];
                if (isset(self::SECTION_BLOCK_HEIGHTS[$props['min_height'] ?? ''])) {
                    $style['dimensions'] = (is_array($style['dimensions'] ?? null) ? $style['dimensions'] : []) + ['min_height' => self::SECTION_BLOCK_HEIGHTS[$props['min_height']]];
                }
                if (is_string($props['background_token'] ?? null)) {
                    $style['surface_token'] = $props['background_token'];
                }
                $block['type'] = CanonicalDocumentSchema::CONTAINER_TYPE;
                $block['version'] = CanonicalDocumentSchema::CONTAINER_VERSION;
                $block['tag'] = is_string($props['tag'] ?? null) ? $props['tag'] : 'section';
                $block['props'] = [];
                $block['style'] = $style;
            }
            $blocks[$i] = $block;
        }
        return $blocks;
    }

    /**
     * @param array<mixed> $blocks
     * @param list<array{path: string, type: string, from: int, to: int}> $upgraded
     * @return array<mixed>
     */
    private static function blocks(array $blocks, BlockRegistry $registry, string $path, array &$upgraded): array
    {
        foreach ($blocks as $j => $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockPath = "{$path}[{$j}]";
            $blocks[$j] = self::block($block, $registry, $blockPath, $upgraded);
            if (is_array($blocks[$j]['children'] ?? null)) {
                $blocks[$j]['children'] = self::blocks($blocks[$j]['children'], $registry, "{$blockPath}.children", $upgraded);
            }
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array{path: string, type: string, from: int, to: int}> $upgraded
     * @return array<string, mixed>
     */
    private static function block(array $block, BlockRegistry $registry, string $path, array &$upgraded): array
    {
        $type = $block['type'] ?? null;
        $from = $block['version'] ?? null;
        $definition = is_string($type) ? $registry->get($type) : null;
        if ($definition === null || !is_int($from) || $from < 1 || $from >= $definition->version() || !is_array($block['props'] ?? null)) {
            return $block;
        }
        $props = $block['props'];
        for ($v = $from; $v < $definition->version(); $v++) {
            try {
                $props = $definition->upgrade($props, $v);
            } catch (\Throwable) {
                $props = null;
            }
            if (!is_array($props)) {
                return $block;
            }
        }
        $block['props'] = $props;
        $block['version'] = $definition->version();
        $upgraded[] = ['path' => $path, 'type' => (string) $type, 'from' => $from, 'to' => $definition->version()];
        return $block;
    }
}
