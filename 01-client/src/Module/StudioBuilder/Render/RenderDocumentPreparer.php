<?php
/**
 * Kohevo Studio (studio-builder) — Validate/normalize a stored document for rendering.
 *
 * Fast path (the normal case): the whole document passes `DocumentValidator`
 * and is normalized by `DocumentNormalizer` — exactly the write-time pipeline.
 *
 * Fail-soft path (security architecture §7: "public rendering may retain
 * narrowly scoped fail-soft compatibility behavior for old trusted data"):
 * a revision that was valid when written can stop validating later — a block
 * type is unregistered, a block version is bumped, a field schema tightens.
 * Rather than taking the whole page down, the envelope (settings, seo,
 * sections) must still validate, and then EVERY block is validated on its own
 * through the same public validator/normalizer. A block that fails becomes an
 * inert `unavailable` marker the renderer turns into a non-executable
 * fallback; it is never rendered from raw, unvalidated input.
 *
 * An undecodable document or an invalid envelope is a hard render failure.
 * This is a READ path only — nothing here ever persists a "repaired" document.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Exception\StudioRenderException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class RenderDocumentPreparer
{
    /** Marker key for an inert, non-renderable block (never a canonical document key). */
    public const UNAVAILABLE_KEY = '__sb_unavailable';

    private const PROBE_SECTION_ID = 'sec_0000000000000000';

    /**
     * @param string|array<string, mixed> $document
     * @return array{document: array<string, mixed>, degraded: bool}
     */
    public static function prepare(string|array $document, BlockRegistry $registry): array
    {
        try {
            $decoded = is_string($document) ? CanonicalJson::decode($document) : $document;
        } catch (StudioValidationException $e) {
            throw new StudioRenderException('Studio document could not be decoded.', ['reason' => 'undecodable'], $e);
        }
        $decoded = DocumentNormalizer::stripTransientMetadata($decoded);

        if (DocumentValidator::validate($decoded, $registry)->isValid()) {
            return ['document' => DocumentNormalizer::normalize($decoded, $registry), 'degraded' => false];
        }

        $rawSections = $decoded['sections'] ?? null;
        if (!is_array($rawSections) || !array_is_list($rawSections)) {
            throw new StudioRenderException('Studio document has no renderable sections.', ['reason' => 'invalid_envelope']);
        }

        $envelope = $decoded;
        foreach ($rawSections as $i => $section) {
            if (is_array($section) && !array_is_list($section)) {
                $envelope['sections'][$i]['blocks'] = [];
            }
        }
        $envelopeResult = DocumentValidator::validate($envelope, $registry);
        if (!$envelopeResult->isValid()) {
            throw new StudioRenderException('Studio document envelope is invalid.', [
                'reason' => 'invalid_envelope',
                'errors' => $envelopeResult->errors(),
            ]);
        }

        $normalized = DocumentNormalizer::normalize($envelope, $registry);
        $seen  = [];
        $count = 0;
        foreach ($normalized['sections'] as $i => $section) {
            $rawBlocks = $rawSections[$i]['blocks'] ?? [];
            $normalized['sections'][$i]['blocks'] = self::prepareBlocks(
                is_array($rawBlocks) && array_is_list($rawBlocks) ? $rawBlocks : [],
                1,
                $registry,
                $seen,
                $count,
            );
        }

        return ['document' => $normalized, 'degraded' => true];
    }

    /**
     * @param list<mixed> $rawBlocks
     * @param array<string, true> $seen
     * @return list<array<string, mixed>>
     */
    private static function prepareBlocks(array $rawBlocks, int $depth, BlockRegistry $registry, array &$seen, int &$count): array
    {
        $out = [];
        foreach ($rawBlocks as $raw) {
            $out[] = self::prepareBlock($raw, $depth, $registry, $seen, $count);
        }
        return $out;
    }

    /**
     * @param array<string, true> $seen
     * @return array<string, mixed>
     */
    private static function prepareBlock(mixed $raw, int $depth, BlockRegistry $registry, array &$seen, int &$count): array
    {
        $count++;
        if ($depth > CanonicalDocumentSchema::MAX_NESTING_DEPTH || $count > CanonicalDocumentSchema::MAX_BLOCKS_PER_DOCUMENT) {
            return self::unavailable('limit_exceeded');
        }
        if (!is_array($raw) || array_is_list($raw)) {
            return self::unavailable('invalid_block');
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || preg_match(CanonicalDocumentSchema::BLOCK_ID_PATTERN, $id) !== 1) {
            return self::unavailable('invalid_block');
        }
        if (isset($seen[$id])) {
            return self::unavailable('duplicate_node_id');
        }
        $seen[$id] = true;

        $type = $raw['type'] ?? null;
        $definition = is_string($type) ? $registry->get($type) : null;
        if ($definition === null) {
            return self::unavailable('unknown_block_type');
        }

        $probeBlock = $raw;
        $probeBlock['children'] = [];
        $probe = CanonicalDocumentSchema::emptyDocument();
        $probe['sections'] = [[
            'blocks'     => [$probeBlock],
            'global_ref' => null,
            'id'         => self::PROBE_SECTION_ID,
            'label'      => 'probe',
            'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        ]];

        $result = DocumentValidator::validate($probe, $registry);
        if (!$result->isValid()) {
            $codes = array_column($result->errors(), 'code');
            return self::unavailable(in_array('invalid_block_version', $codes, true) ? 'unsupported_block_version' : 'invalid_block');
        }

        $block = DocumentNormalizer::normalize($probe, $registry)['sections'][0]['blocks'][0];
        $children = $raw['children'] ?? [];
        $block['children'] = ($definition->allowsChildren() && is_array($children) && array_is_list($children))
            ? self::prepareBlocks($children, $depth + 1, $registry, $seen, $count)
            : [];

        return $block;
    }

    /** @return array<string, string> */
    private static function unavailable(string $reason): array
    {
        return [self::UNAVAILABLE_KEY => $reason];
    }
}
