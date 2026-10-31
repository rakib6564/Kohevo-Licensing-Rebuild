<?php
/**
 * Kohevo Studio (studio-builder) — Deterministic Document Normalizer.
 *
 * Guarantees idempotence and canonical key ordering:
 *     normalize(normalize($document)) === normalize($document)
 *     CanonicalJson::fingerprint(normalize($document)) === CanonicalJson::fingerprint(normalize(normalize($document)))
 *
 * - Strips transient editor-only metadata (`_editor`, `_ui`, `_selected`, etc.) when called via `stripTransientMetadata()`.
 * - Validates and normalizes `settings`, `seo`, `containers`, `visibility`, `props`, `style`, `bindings`, and `children`.
 * - Applies registered `FieldSchema` defaults to every block's `props`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Exception\StudioValidationException;
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

        // A document in the older format is accepted from anywhere and comes out in the current one.
        $cleaned = DocumentUpgrader::structure(self::stripTransientMetadata($decoded));
        DocumentValidator::assertValid($cleaned, $registry, $validationOptions);

        $normalized = self::normalize($cleaned, $registry);
        self::assertReadable($normalized);
        return $normalized;
    }

    /**
     * Refuse a document whose stored form could not be read back.
     *
     * Every read decodes `document_json` with `CanonicalJson::decode()` (1 MiB, depth 32), but the validator measures the
     * document before normalization fills in defaults, and only bounds the depth of the block tree. A document just under
     * the size limit, or with a deep value under a key the validator does not walk (`responsive.tablet.extra`), was
     * accepted and committed, and then every open, publish and preview of the page failed. Checking the exact encoding
     * that will be stored, against the limits the reads use, keeps "saved" and "can be opened" the same thing.
     *
     * @param array<string, mixed> $normalized
     */
    private static function assertReadable(array $normalized): void
    {
        $encoded = CanonicalJson::encode($normalized);
        $bytes   = strlen($encoded);
        if ($bytes > CanonicalDocumentSchema::MAX_DOCUMENT_BYTES) {
            throw new StudioValidationException([[
                'path'    => '$',
                'code'    => 'document_too_large',
                'message' => 'Document is ' . $bytes . ' bytes once saved; the limit is ' . CanonicalDocumentSchema::MAX_DOCUMENT_BYTES . ' bytes.',
            ]]);
        }
        try {
            json_decode($encoded, true, CanonicalDocumentSchema::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StudioValidationException([[
                'path'    => '$',
                'code'    => 'document_too_deep',
                'message' => 'Document nests deeper than ' . CanonicalDocumentSchema::MAX_JSON_DEPTH . ' levels once saved.',
            ]]);
        }
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

        $containers = [];
        foreach ((array) ($cleaned['containers'] ?? []) as $container) {
            if (is_array($container)) {
                $containers[] = self::normalizeBlock($container, $registry);
            }
        }

        $normalized = [
            'document_type'  => (string) ($cleaned['document_type'] ?? 'page'),
            'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
            'containers'     => $containers,
            'seo'            => CanonicalJson::sortKeysRecursively($seo),
            'settings'       => CanonicalJson::sortKeysRecursively($settings),
            'template_key'   => (string) ($cleaned['template_key'] ?? 'default'),
        ];

        return CanonicalJson::sortKeysRecursively($normalized);
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
        // A live Global Component reference: present only while set.
        if (is_string($block['global_ref'] ?? null) && $block['global_ref'] !== '') {
            $normalizedBlock['global_ref'] = $block['global_ref'];
        }
        // The wrapper tag: absent for the default `div`.
        if (isset($block['tag']) && is_string($block['tag']) && $block['tag'] !== 'div') {
            $normalizedBlock['tag'] = $block['tag'];
        }
        // Where an `a` wrapper points: kept with that tag only.
        if (($normalizedBlock['tag'] ?? null) === 'a' && is_array($block['link'] ?? null) && $block['link'] !== []) {
            $normalizedBlock['link'] = CanonicalJson::sortKeysRecursively($block['link']);
        }
        // Part styles, like states: an empty part (and an empty map) is dropped.
        if (is_string($block['custom_css'] ?? null) && trim($block['custom_css']) !== '') {
            $normalizedBlock['custom_css'] = $block['custom_css'];
        }
        if (array_key_exists('style_parts', $block) && is_array($block['style_parts'])) {
            $parts = [];
            foreach ($block['style_parts'] as $name => $partial) {
                if (is_array($partial) && $partial !== []) {
                    $parts[(string) $name] = CanonicalJson::sortKeysRecursively($partial);
                }
            }
            if ($parts !== []) {
                $normalizedBlock['style_parts'] = CanonicalJson::sortKeysRecursively($parts);
            }
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
