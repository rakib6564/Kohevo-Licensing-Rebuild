<?php
/**
 * Kohevo Studio (studio-builder) — Canonical Document Dependency Extractor.
 *
 * Walks a normalized Studio document tree and extracts deterministic
 * `DependencyRecord` entries for:
 * - Document-level token group (`settings.token_group` -> `token_group`)
 * - Document-level non-default template (`template_key` -> `partial`)
 * - Document-level SEO OpenGraph image (`seo.og_image_media_id` -> `media`)
 * - Section-level global reference (`section.global_ref` -> `partial`, key = the
 *   referenced Global Component's page uuid — Phase 6 live reference)
 * - Document-level chrome bindings (`settings.header_mode` / `footer_mode` other
 *   than `hidden` on a chromed document -> `partial` `chrome:header` / `chrome:footer`),
 *   so publishing a header/footer partial can drop exactly the artifacts that show chrome
 * - Section-level background token (`section.layout.background_token` -> `token_group`)
 * - Block-level style tokens (`block.style.*_token` -> `token_group`)
 * - Block-level props, bindings, and module entitlements via `BlockDefinitionInterface::extractDependencies()`
 * - Nested child blocks recursively
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Dependency;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class DependencyExtractor
{
    public const ROOT_NODE_ID = 'doc_root';

    /** `partial` dependency keys of the two chrome regions (documents that show a header / footer). */
    public const CHROME_HEADER_KEY = 'chrome:header';
    public const CHROME_FOOTER_KEY = 'chrome:footer';

    /**
     * Extract deduplicated, deterministically ordered dependency records from a normalized document.
     *
     * @param array<string, mixed> $normalizedDocument
     * @return list<DependencyRecord>
     */
    public static function extract(array $normalizedDocument, BlockRegistry $registry): array
    {
        /** @var array<string, DependencyRecord> $bySignature */
        $bySignature = [];

        $add = static function (DependencyRecord $record) use (&$bySignature): void {
            $sig = $record->uniqueSignature();
            if (!isset($bySignature[$sig])) {
                $bySignature[$sig] = $record;
            }
        };

        // 1. Document-level settings & SEO dependencies
        $tokenGroup = $normalizedDocument['settings']['token_group'] ?? null;
        if (is_string($tokenGroup) && $tokenGroup !== '') {
            $add(new DependencyRecord(self::ROOT_NODE_ID, 'token_group', $tokenGroup));
        }

        $templateKey = $normalizedDocument['template_key'] ?? null;
        if (is_string($templateKey) && $templateKey !== '' && $templateKey !== 'default') {
            $add(new DependencyRecord(self::ROOT_NODE_ID, 'partial', 'template:' . $templateKey));
        }

        $ogMediaId = $normalizedDocument['seo']['og_image_media_id'] ?? null;
        if (is_int($ogMediaId) && $ogMediaId > 0) {
            $add(new DependencyRecord(self::ROOT_NODE_ID, 'media', (string) $ogMediaId));
        }

        $docType = (string) ($normalizedDocument['document_type'] ?? '');
        if (in_array($docType, CanonicalDocumentSchema::CHROMED_DOCUMENT_TYPES, true)) {
            if (($normalizedDocument['settings']['header_mode'] ?? 'inherit') !== 'hidden') {
                $add(new DependencyRecord(self::ROOT_NODE_ID, 'partial', self::CHROME_HEADER_KEY));
            }
            if (($normalizedDocument['settings']['footer_mode'] ?? 'inherit') !== 'hidden') {
                $add(new DependencyRecord(self::ROOT_NODE_ID, 'partial', self::CHROME_FOOTER_KEY));
            }
        }

        // 2. Section and Block dependencies
        $sections = is_array($normalizedDocument['sections'] ?? null) ? $normalizedDocument['sections'] : [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $secId = (string) ($section['id'] ?? 'sec_unknown');

            if (!empty($section['global_ref']) && is_string($section['global_ref'])) {
                $add(new DependencyRecord($secId, 'partial', $section['global_ref']));
            }

            $bgToken = $section['layout']['background_token'] ?? null;
            if (is_string($bgToken) && $bgToken !== '') {
                $add(new DependencyRecord($secId, 'token_group', $bgToken));
            }

            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            foreach ($blocks as $block) {
                if (is_array($block)) {
                    self::extractBlockDependencies($block, $registry, $add);
                }
            }
        }

        return array_values($bySignature);
    }

    /**
     * @param array<string, mixed> $block
     * @param callable(DependencyRecord): void $add
     */
    private static function extractBlockDependencies(array $block, BlockRegistry $registry, callable $add): void
    {
        $blockId = (string) ($block['id'] ?? 'blk_unknown');
        $type    = (string) ($block['type'] ?? '');
        $props   = is_array($block['props'] ?? null) ? $block['props'] : [];
        $style   = is_array($block['style'] ?? null) ? $block['style'] : [];
        $bindings = is_array($block['bindings'] ?? null) ? $block['bindings'] : [];

        // Style tokens on block
        foreach (['surface_token', 'text_token', 'spacing_token', 'radius_token', 'shadow_token', 'font_token'] as $tokenField) {
            $tokenVal = $style[$tokenField] ?? null;
            if (is_string($tokenVal) && $tokenVal !== '') {
                $add(new DependencyRecord($blockId, 'token_group', $tokenVal));
            }
        }

        // A background image is a media reference held in the style, not in props.
        $bgImage = is_array($style['background'] ?? null) ? ($style['background']['image'] ?? null) : null;
        if (is_array($bgImage) && isset($bgImage['media_id']) && is_int($bgImage['media_id']) && $bgImage['media_id'] > 0) {
            $add(new DependencyRecord($blockId, 'media', (string) $bgImage['media_id']));
        }

        // Block definition dependencies (entitlement module, media_ref, token_ref, bindings)
        $def = $registry->get($type);
        if ($def !== null) {
            foreach ($def->extractDependencies($blockId, $props, $bindings) as $record) {
                $add($record);
            }
        }

        // Recurse into children
        $children = is_array($block['children'] ?? null) ? $block['children'] : [];
        foreach ($children as $child) {
            if (is_array($child)) {
                self::extractBlockDependencies($child, $registry, $add);
            }
        }
    }
}
