<?php
/**
 * Kohevo Studio (studio-builder) — Legacy Document Converter.
 *
 * Pure, isolated conversion utility for translating historical v1/v2 document
 * fixtures into a Kohevo Studio Canonical Document (`schema_version = "1.0"`).
 *
 * IMPORTANT:
 * - Never called automatically by `DocumentValidator` (Studio write paths accept
 *   ONLY `schema_version = "1.0"`).
 * - Does not read or revive dormant legacy tables (`content_pages`, `content_revisions`).
 * - Output must still pass `DocumentValidator::validate()` and `DocumentNormalizer::normalize()`
 *   before any Studio persistence.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

final class LegacyDocumentConverter
{
    /**
     * Convert a historical v1 envelope (`{"schema": 1, "sections": [...]}`) or v2 prototype
     * (`{"version": 2, "header": {...}, "sections": [...]}`) into a Studio `"1.0"` document structure.
     *
     * @param array<string, mixed> $legacy
     * @param array<string, string> $blockTypeMap Optional map of legacy block types to Studio namespaced types
     * @return array<string, mixed>
     */
    public static function convertToCanonicalV1(array $legacy, array $blockTypeMap = []): array
    {
        $docType     = 'page';
        $templateKey = 'default';
        $seoTitle    = '';
        $seoDesc     = '';

        if (isset($legacy['version']) && (int) $legacy['version'] === 2 && is_array($legacy['header'] ?? null)) {
            $header      = $legacy['header'];
            $docType     = self::mapDocumentType((string) ($header['type'] ?? 'page'));
            $templateKey = self::sanitizeTemplateKey((string) ($header['template'] ?? 'default'));
            $seoTitle    = (string) ($header['seo']['title'] ?? ($header['title'] ?? ''));
            $seoDesc     = (string) ($header['seo']['description'] ?? '');
        } else {
            $docType     = self::mapDocumentType((string) ($legacy['type'] ?? 'page'));
            $templateKey = self::sanitizeTemplateKey((string) ($legacy['template'] ?? 'default'));
            $seoTitle    = (string) ($legacy['seo']['title'] ?? '');
            $seoDesc     = (string) ($legacy['seo']['description'] ?? '');
        }

        $doc = CanonicalDocumentSchema::emptyDocument($docType, $templateKey, $seoTitle);
        $doc['seo']['description'] = $seoDesc;

        $rawSections = is_array($legacy['sections'] ?? null) ? $legacy['sections'] : [];
        $sections = [];

        foreach ($rawSections as $idx => $rawSec) {
            if (!is_array($rawSec)) {
                continue;
            }
            $secSeed = (string) ($rawSec['id'] ?? ('s_' . $idx));
            $secId   = 'sec_' . substr(hash('sha256', 'sec:' . $secSeed . ':' . $idx), 0, 24);
            $label   = (string) ($rawSec['name'] ?? ($rawSec['label'] ?? ('Section ' . ($idx + 1))));

            $blocks = [];
            foreach ((array) ($rawSec['blocks'] ?? []) as $bIdx => $rawBlk) {
                if (!is_array($rawBlk) || empty($rawBlk['type'])) {
                    continue;
                }
                $legacyType = (string) $rawBlk['type'];
                $mappedType = $blockTypeMap[$legacyType] ?? (str_contains($legacyType, '.') ? $legacyType : 'core.' . preg_replace('/[^a-z0-9_]/', '_', strtolower($legacyType)));
                $blkSeed    = (string) ($rawBlk['id'] ?? ('b_' . $idx . '_' . $bIdx));
                $blkId      = 'blk_' . substr(hash('sha256', 'blk:' . $blkSeed . ':' . $idx . ':' . $bIdx), 0, 24);

                $props = is_array($rawBlk['props'] ?? null) ? $rawBlk['props'] : [];
                unset($props['tenant_id']);

                $blocks[] = [
                    'bindings'   => [],
                    'children'   => [],
                    'id'         => $blkId,
                    'props'      => $props,
                    'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                    'type'       => $mappedType,
                    'version'    => 1,
                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                ];
            }

            $sections[] = [
                'blocks'     => $blocks,
                'global_ref' => null,
                'id'         => $secId,
                'label'      => $label,
                'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
                'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            ];
        }

        $doc['sections'] = $sections;
        return $doc;
    }

    private static function mapDocumentType(string $type): string
    {
        return in_array($type, CanonicalDocumentSchema::ALLOWED_DOCUMENT_TYPES, true) ? $type : 'page';
    }

    private static function sanitizeTemplateKey(string $template): string
    {
        $trimmed = strtolower(trim($template));
        if ($trimmed === '' || preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $trimmed) !== 1) {
            return 'default';
        }
        return $trimmed;
    }
}
