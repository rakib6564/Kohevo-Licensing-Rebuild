<?php
/**
 * Kohevo Studio (studio-builder) — Deterministic Document Normalizer.
 *
 * Guarantees idempotence and canonical key ordering:
 *     normalize(normalize($document)) === normalize($document)
 *     CanonicalJson::fingerprint(normalize($document)) === CanonicalJson::fingerprint(normalize(normalize($document)))
 *
 * - Strips transient editor-only metadata (`_editor`, `_ui`, `_selected`, etc.) when called via `stripTransientMetadata()`.
 * - Validates and normalizes `settings`, `seo`, `sections`, `layout`, `visibility`, `blocks`, `props`, `style`, `bindings`, and `children`.
 * - Applies registered `FieldSchema` defaults to every block's `props`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class DocumentNormalizer
{
    /**
     * Strip transient editor-only keys (`_editor`, `_ui`, `_selected`, `_hovered`, `_collapsed`, `_dirty`)
     * from an in-memory document structure before validation.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function stripTransientMetadata(array $document): array
    {
        return self::stripTransientNode($document);
    }

    /**
     * Validate and deterministically normalize a canonical Studio document.
     * Throws `StudioValidationException` if the document is invalid.
     *
     * @param string|array<string, mixed> $document
     * @param array<string, mixed>        $validationOptions
     * @return array<string, mixed>
     */
    public static function validateAndNormalize(
        string|array $document,
        BlockRegistry $registry,
        array $validationOptions = [],
    ): array {
        $decoded = is_string($document)
            ? CanonicalJson::decode($document, (int) ($validationOptions['max_bytes'] ?? CanonicalDocumentSchema::MAX_DOCUMENT_BYTES))
            : $document;

        $cleaned = self::stripTransientMetadata($decoded);
        DocumentValidator::assertValid($cleaned, $registry, $validationOptions);

        return self::normalize($cleaned, $registry);
    }

    /**
     * Deterministically normalize an already-validated canonical document array.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalize(array $document, BlockRegistry $registry): array
    {
        $cleaned = self::stripTransientMetadata($document);

        $settings = array_merge(
            CanonicalDocumentSchema::defaultSettings(),
            is_array($cleaned['settings'] ?? null) ? $cleaned['settings'] : []
        );

        $seo = array_merge(
            CanonicalDocumentSchema::defaultSeo(),
            is_array($cleaned['seo'] ?? null) ? $cleaned['seo'] : []
        );
        $seo['title']       = (string) ($seo['title'] ?? '');
        $seo['description'] = (string) ($seo['description'] ?? '');
        $seo['robots']      = (string) ($seo['robots'] ?? 'index,follow');
        $seo['canonical_url'] = isset($seo['canonical_url']) && $seo['canonical_url'] !== ''
            ? trim((string) $seo['canonical_url'])
            : null;
        $seo['og_image_media_id'] = isset($seo['og_image_media_id']) && $seo['og_image_media_id'] !== null
            ? (int) $seo['og_image_media_id']
            : null;

        $normalizedSections = [];
        foreach ((array) ($cleaned['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $normalizedSections[] = self::normalizeSection($section, $registry);
            }
        }

        $normalized = [
            'document_type'  => (string) ($cleaned['document_type'] ?? 'page'),
            'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
            'sections'       => $normalizedSections,
            'seo'            => CanonicalJson::sortKeysRecursively($seo),
            'settings'       => CanonicalJson::sortKeysRecursively($settings),
            'template_key'   => (string) ($cleaned['template_key'] ?? 'default'),
        ];

        return CanonicalJson::sortKeysRecursively($normalized);
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private static function normalizeSection(array $section, BlockRegistry $registry): array
    {
        $rawLayout = is_array($section['layout'] ?? null) ? $section['layout'] : [];
        $layout    = array_merge(CanonicalDocumentSchema::defaultSectionLayout(), $rawLayout);

        if (is_int($layout['columns'])) {
            $layout['columns'] = ['base' => $layout['columns']];
        } elseif (is_array($layout['columns'])) {
            $layout['columns'] = CanonicalJson::sortKeysRecursively($layout['columns']);
        }

        if (is_string($layout['padding_y'])) {
            $layout['padding_y'] = ['base' => $layout['padding_y']];
        } elseif (is_array($layout['padding_y'])) {
            $layout['padding_y'] = CanonicalJson::sortKeysRecursively($layout['padding_y']);
        }

        $visibility = self::normalizeVisibility(
            is_array($section['visibility'] ?? null) ? $section['visibility'] : []
        );

        $normalizedBlocks = [];
        foreach ((array) ($section['blocks'] ?? []) as $block) {
            if (is_array($block)) {
                $normalizedBlocks[] = self::normalizeBlock($block, $registry);
            }
        }

        $normalizedSection = [
            'blocks'     => $normalizedBlocks,
            'global_ref' => isset($section['global_ref']) && $section['global_ref'] !== '' ? (string) $section['global_ref'] : null,
            'id'         => (string) $section['id'],
            'label'      => trim((string) ($section['label'] ?? '')),
            'layout'     => CanonicalJson::sortKeysRecursively($layout),
            'visibility' => $visibility,
        ];
        // Canonical form: the lock key exists only while the section is locked.
        if (($section['locked'] ?? null) === true) {
            $normalizedSection['locked'] = true;
        }

        return CanonicalJson::sortKeysRecursively($normalizedSection);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function normalizeBlock(array $block, BlockRegistry $registry): array
    {
        $type = (string) $block['type'];
        $def  = $registry->get($type);

        $rawProps = is_array($block['props'] ?? null) ? $block['props'] : [];
        $normalizedProps = $def !== null
            ? $def->normalizeProps($rawProps)
            : CanonicalJson::sortKeysRecursively($rawProps);

        $rawStyle = is_array($block['style'] ?? null) ? $block['style'] : [];
        $style    = array_merge(CanonicalDocumentSchema::defaultBlockStyle(), $rawStyle);
        if (is_string($style['align'])) {
            $style['align'] = ['base' => $style['align']];
        } elseif (is_array($style['align'])) {
            $style['align'] = CanonicalJson::sortKeysRecursively($style['align']);
        }

        $visibility = self::normalizeVisibility(
            is_array($block['visibility'] ?? null) ? $block['visibility'] : []
        );

        $rawBindings = is_array($block['bindings'] ?? null) ? $block['bindings'] : [];
        $bindings    = [];
        foreach ($rawBindings as $slot => $desc) {
            if (is_array($desc)) {
                $bindings[(string) $slot] = [
                    'mapping'  => CanonicalJson::sortKeysRecursively(is_array($desc['mapping'] ?? null) ? $desc['mapping'] : []),
                    'params'   => CanonicalJson::sortKeysRecursively(is_array($desc['params'] ?? null) ? $desc['params'] : []),
                    'provider' => (string) ($desc['provider'] ?? ''),
                ];
            }
        }

        $normalizedChildren = [];
        foreach ((array) ($block['children'] ?? []) as $child) {
            if (is_array($child)) {
                $normalizedChildren[] = self::normalizeBlock($child, $registry);
            }
        }

        $normalizedBlock = [
            'bindings'   => CanonicalJson::sortKeysRecursively($bindings),
            'children'   => $normalizedChildren,
            'id'         => (string) $block['id'],
            'props'      => $normalizedProps,
            'style'      => CanonicalJson::sortKeysRecursively($style),
            'type'       => $type,
            'version'    => (int) $block['version'],
            'visibility' => $visibility,
        ];

        if (array_key_exists('responsive', $block) && is_array($block['responsive'])) {
            $normalizedBlock['responsive'] = CanonicalJson::sortKeysRecursively($block['responsive']);
        }
        if (array_key_exists('attributes', $block) && is_array($block['attributes'])) {
            $normalizedBlock['attributes'] = CanonicalJson::sortKeysRecursively($block['attributes']);
        }
        if (array_key_exists('classNames', $block)) {
            $normalizedBlock['classNames'] = is_array($block['classNames']) ? array_values($block['classNames']) : (string) $block['classNames'];
        }
        if (array_key_exists('animation', $block) && is_array($block['animation'])) {
            $normalizedBlock['animation'] = CanonicalJson::sortKeysRecursively($block['animation']);
        }
        // Interaction states: empty states (and an empty map) are dropped, so the canonical form has no `style_states` unless one does something.
        if (array_key_exists('style_states', $block) && is_array($block['style_states'])) {
            $states = [];
            foreach ($block['style_states'] as $name => $partial) {
                if (is_array($partial) && $partial !== []) {
                    $states[(string) $name] = CanonicalJson::sortKeysRecursively($partial);
                }
            }
            if ($states !== []) {
                $normalizedBlock['style_states'] = CanonicalJson::sortKeysRecursively($states);
            }
        }
        if (array_key_exists('interactions', $block) && is_array($block['interactions'])) {
            $normalizedBlock['interactions'] = CanonicalJson::sortKeysRecursively($block['interactions']);
        }
        // Editor metadata: trimmed label and/or `locked: true`; absent when empty (canonical form).
        if (array_key_exists('metadata', $block) && is_array($block['metadata'])) {
            $meta  = [];
            $label = isset($block['metadata']['label']) && is_string($block['metadata']['label']) ? trim($block['metadata']['label']) : '';
            if ($label !== '') {
                $meta['label'] = $label;
            }
            if (($block['metadata']['locked'] ?? null) === true) {
                $meta['locked'] = true;
            }
            if ($meta !== []) {
                $normalizedBlock['metadata'] = $meta;
            }
        }

        return CanonicalJson::sortKeysRecursively($normalizedBlock);
    }

    /**
     * @param array<string, mixed> $visibility
     * @return array<string, mixed>
     */
    private static function normalizeVisibility(array $visibility): array
    {
        $merged  = array_merge(CanonicalDocumentSchema::defaultVisibility(), $visibility);
        $devices = is_array($merged['devices'] ?? null) ? $merged['devices'] : CanonicalDocumentSchema::ALLOWED_BREAKPOINTS;

        // Deduplicate devices in canonical breakpoint order (`base`, `sm`, `md`, `lg`)
        $orderedDevices = [];
        foreach (CanonicalDocumentSchema::ALLOWED_BREAKPOINTS as $bp) {
            if (in_array($bp, $devices, true)) {
                $orderedDevices[] = $bp;
            }
        }

        return [
            'auth_state' => (string) ($merged['auth_state'] ?? 'any'),
            'devices'    => $orderedDevices,
        ];
    }

    private static function stripTransientNode(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::stripTransientNode($item);
            }
            return $out;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $key = (string) $k;
            if (in_array($key, CanonicalDocumentSchema::TRANSIENT_EDITOR_KEYS, true)) {
                continue;
            }
            $out[$key] = self::stripTransientNode($v);
        }
        return $out;
    }
}
