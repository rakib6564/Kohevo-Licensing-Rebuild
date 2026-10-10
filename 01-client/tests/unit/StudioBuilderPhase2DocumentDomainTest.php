<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 2 Canonical Document Domain.
 *
 * Dependency-free (no database): exercises `Slate\Module\StudioBuilder\*` pure
 * domain classes via the autoloader only, per the Phase 1 harness convention.
 *
 * Verifies:
 *   1. CanonicalDocumentSchema shape helpers and opaque id generation.
 *   2. CanonicalJson determinism (key sorting, fingerprint stability) and limits.
 *   3. FieldSchema validation/normalization across all supported field types,
 *      and its unsafe-content / unsafe-URL / rich-text guards.
 *   4. BlockRegistry core foundation blocks, duplicate rejection, manifest filtering.
 *   5. DocumentValidator + DocumentNormalizer: idempotence and fail-closed invariants.
 *   6. DependencyExtractor: deterministic, deduplicated dependency extraction.
 *   7. LegacyDocumentConverter: v1/v2 fixtures convert into a schema "1.0" document.
 *   8. DocumentOperation + DocumentOperationApplier: structural mutation & fail-closed guards.
 *   9. PageAddress domain value object invariants.
 *  10. StudioTemplate domain value object invariants.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioP2UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Document\LegacyDocumentConverter;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Domain\StudioTemplate;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

// ── Local fixture helpers ───────────────────────────────────────────────────

function sb2_registry(): BlockRegistry
{
    return BlockRegistry::withCoreFoundationBlocks();
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sb2_block(BlockRegistry $registry, string $type, array $overrides = []): array
{
    $def = $registry->get($type);
    assert_true($def !== null, "sb2_block: block type '{$type}' must be registered");

    return array_merge([
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => $def->schema()->defaults(),
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => $type,
        'version'    => $def->version(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $overrides);
}

/**
 * @param list<array<string, mixed>> $blocks
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sb2_section(array $blocks = [], array $overrides = []): array
{
    return array_merge([
        'blocks'     => $blocks,
        'global_ref' => null,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => 'Section',
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $overrides);
}

/**
 * @param list<array<string, mixed>> $sections
 * @return array<string, mixed>
 */
function sb2_document(array $sections = []): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Test Page');
    $doc['sections'] = $sections;
    return $doc;
}

// ── 1. CanonicalDocumentSchema ──────────────────────────────────────────────

unit('phase2 document domain: CanonicalDocumentSchema shape helpers and opaque id generation', function (): void {
    $doc = CanonicalDocumentSchema::emptyDocument('landing', 'promo', 'My Title');
    assert_eq('landing', $doc['document_type']);
    assert_eq('promo', $doc['template_key']);
    assert_eq('My Title', $doc['seo']['title']);
    assert_eq(CanonicalDocumentSchema::SCHEMA_VERSION, $doc['schema_version']);
    assert_eq([], $doc['sections']);

    $sec1 = CanonicalDocumentSchema::newSectionId();
    $sec2 = CanonicalDocumentSchema::newSectionId();
    assert_true((bool) preg_match(CanonicalDocumentSchema::SECTION_ID_PATTERN, $sec1));
    assert_true($sec1 !== $sec2, 'section ids must be unique per call');

    $blk1 = CanonicalDocumentSchema::newBlockId();
    assert_true((bool) preg_match(CanonicalDocumentSchema::BLOCK_ID_PATTERN, $blk1));
});

// ── 2. CanonicalJson ─────────────────────────────────────────────────────────

unit('phase2 document domain: CanonicalJson key-order determinism and fingerprint stability', function (): void {
    $a = ['b' => 1, 'a' => ['d' => 2, 'c' => 3]];
    $b = ['a' => ['c' => 3, 'd' => 2], 'b' => 1];

    assert_eq(CanonicalJson::encode($a), CanonicalJson::encode($b), 'differently-ordered equal maps must encode identically');
    assert_eq(CanonicalJson::fingerprint($a), CanonicalJson::fingerprint($b));
    assert_eq(64, strlen(CanonicalJson::fingerprint($a)));

    $decoded = CanonicalJson::decode('{"x":1,"y":[1,2,3]}');
    assert_eq(1, $decoded['x']);
    assert_eq([1, 2, 3], $decoded['y']);

    assert_throws(StudioValidationException::class, function (): void {
        CanonicalJson::decode('');
    }, 'empty JSON must be rejected');

    assert_throws(StudioValidationException::class, function (): void {
        CanonicalJson::decode('not json');
    }, 'malformed JSON must be rejected');

    assert_throws(StudioValidationException::class, function (): void {
        CanonicalJson::decode('[1,2,3]');
    }, 'a JSON array root must be rejected (object required)');

    assert_throws(StudioValidationException::class, function (): void {
        CanonicalJson::decode('{"a":1}', 4);
    }, 'oversized JSON payload must be rejected');
});

// ── 3. FieldSchema ────────────────────────────────────────────────────────────

unit('phase2 document domain: FieldSchema validation and normalization across field types', function (): void {
    $schema = FieldSchema::define([
        ['key' => 'title', 'type' => 'string', 'required' => true, 'max_length' => 10],
        ['key' => 'count', 'type' => 'number', 'integer_only' => true, 'min' => 0, 'max' => 5, 'default' => 1],
        ['key' => 'active', 'type' => 'boolean', 'default' => true],
        ['key' => 'mode', 'type' => 'enum', 'allowed_values' => ['a', 'b'], 'default' => 'a'],
        ['key' => 'href', 'type' => 'url'],
        ['key' => 'accent', 'type' => 'token_ref'],
    ]);

    $ok = $schema->validate(['title' => 'Hello', 'count' => 3, 'active' => false, 'mode' => 'b', 'href' => '/x', 'accent' => 'color.primary']);
    assert_true($ok->isValid(), 'valid props must pass: ' . json_encode($ok->errors()));

    $unknownKey = $schema->validate(['title' => 'Hi', 'nope' => 1]);
    assert_false($unknownKey->isValid());

    $missingRequired = $schema->validate([]);
    assert_false($missingRequired->isValid());

    $tooLong = $schema->validate(['title' => 'this is way too long']);
    assert_false($tooLong->isValid());

    $badEnum = $schema->validate(['title' => 'Hi', 'mode' => 'z']);
    assert_false($badEnum->isValid());

    $badUrl = $schema->validate(['title' => 'Hi', 'href' => 'javascript:alert(1)']);
    assert_false($badUrl->isValid());

    $normalized = $schema->normalize(['title' => 'Hi']);
    assert_eq(1, $normalized['count'], 'missing optional number must fall back to default');
    assert_eq(true, $normalized['active']);
    assert_eq('a', $normalized['mode']);

    assert_true(FieldSchema::isSafeUrl('/relative/path'));
    assert_true(FieldSchema::isSafeUrl('https://example.com/x'));
    assert_true(FieldSchema::isSafeUrl('mailto:a@b.com'));
    assert_false(FieldSchema::isSafeUrl('javascript:alert(1)'));
    assert_false(FieldSchema::isSafeUrl('//evil.example.com'));
    assert_false(FieldSchema::isSafeUrl('data:text/html;base64,abc'));

    assert_true(FieldSchema::containsExecutableOrSqlFragment('<script>alert(1)</script>'));
    assert_true(FieldSchema::containsExecutableOrSqlFragment("1; DROP TABLE users"));
    assert_false(FieldSchema::containsExecutableOrSqlFragment('A perfectly normal sentence.'));

    assert_null(FieldSchema::validateRichText('<p>Hello <strong>world</strong></p>'));
    assert_true(FieldSchema::validateRichText('<script>bad()</script>') !== null);
    assert_true(FieldSchema::validateRichText('<p onclick="bad()">x</p>') !== null);

    $repeaterSchema = FieldSchema::define([
        [
            'key'        => 'items',
            'type'       => 'repeater',
            'max_items'  => 2,
            'item_schema' => FieldSchema::define([
                ['key' => 'label', 'type' => 'string', 'required' => true],
            ]),
        ],
    ]);
    $tooMany = $repeaterSchema->validate(['items' => [['label' => 'a'], ['label' => 'b'], ['label' => 'c']]]);
    assert_false($tooMany->isValid(), 'repeater must enforce max_items');
});

// ── 4. BlockRegistry ─────────────────────────────────────────────────────────

unit('phase2 document domain: BlockRegistry core foundation blocks, duplicate rejection, manifest filtering', function (): void {
    $registry = sb2_registry();

    foreach (['core.hero', 'core.heading', 'core.rich_text', 'core.image', 'core.button', 'core.feature_list', 'core.container'] as $type) {
        assert_true($registry->has($type), "registry must contain {$type}");
    }
    assert_null($registry->get('core.does_not_exist'));

    assert_throws(\InvalidArgumentException::class, function () use ($registry): void {
        $registry->register(new DeclarativeBlockDefinition(
            type: 'core.hero',
            version: 1,
            label: 'Duplicate',
            category: 'layout',
            icon: 'x',
            schema: FieldSchema::define([]),
        ));
    }, 'registering a duplicate block type must fail closed');

    $fullManifest = $registry->editorManifests();
    assert_eq(7, count($fullManifest));

    $moduleBlock = new DeclarativeBlockDefinition(
        type: 'forms.embed',
        version: 1,
        label: 'Form Embed',
        category: 'forms',
        icon: 'form',
        schema: FieldSchema::define([['key' => 'form_id', 'type' => 'number', 'integer_only' => true, 'required' => true]]),
        requiredEntitlement: 'forms',
    );
    $registry->register($moduleBlock);

    $filtered = $registry->editorManifests(static fn(?string $ent): bool => $ent === null);
    assert_eq(7, count($filtered), 'entitlement filter must exclude the module-gated block when entitlement is absent');

    $withLocked = $registry->editorManifests(static fn(?string $ent): bool => $ent === null, null, true);
    assert_eq(8, count($withLocked), 'includeLocked keeps the module-gated block in the list');
    $lockedRows = array_values(array_filter($withLocked, static fn(array $m): bool => !empty($m['locked'])));
    assert_eq(1, count($lockedRows), 'only the unentitled block is marked locked');
    assert_eq('forms.embed', $lockedRows[0]['type']);
    assert_eq('forms', $lockedRows[0]['locked_module']);
});

// ── 5. DocumentValidator + DocumentNormalizer ───────────────────────────────

unit('phase2 document domain: DocumentValidator/DocumentNormalizer idempotence on a valid document', function (): void {
    $registry = sb2_registry();
    $hero = sb2_block($registry, 'core.hero', ['props' => ['heading' => 'Welcome', 'eyebrow' => '', 'subheading' => '', 'primary_cta' => null, 'media' => null, 'accent_token' => null]]);
    $doc = sb2_document([sb2_section([$hero])]);

    $result = DocumentValidator::validate($doc, $registry);
    assert_true($result->isValid(), 'well-formed document must validate: ' . json_encode($result->errors()));

    $normalizedOnce  = DocumentNormalizer::normalize($doc, $registry);
    $normalizedTwice = DocumentNormalizer::normalize($normalizedOnce, $registry);
    assert_eq(CanonicalJson::fingerprint($normalizedOnce), CanonicalJson::fingerprint($normalizedTwice), 'normalize() must be idempotent');

    $roundTripped = DocumentNormalizer::validateAndNormalize(CanonicalJson::encode($normalizedOnce), $registry);
    assert_eq(CanonicalJson::fingerprint($normalizedOnce), CanonicalJson::fingerprint($roundTripped));
});

unit('phase2 document domain: DocumentValidator fails closed on structural violations', function (): void {
    $registry = sb2_registry();

    $missingSchemaVersion = sb2_document();
    unset($missingSchemaVersion['schema_version']);
    assert_false(DocumentValidator::validate($missingSchemaVersion, $registry)->isValid());

    $unknownTopLevel = sb2_document();
    $unknownTopLevel['not_a_real_key'] = 1;
    assert_false(DocumentValidator::validate($unknownTopLevel, $registry)->isValid());

    $forbiddenTenantId = sb2_document();
    $forbiddenTenantId['settings']['tenant_id'] = 5;
    $tenantResult = DocumentValidator::validate($forbiddenTenantId, $registry);
    assert_false($tenantResult->isValid());
    $codes = array_map(static fn(array $e): string => $e['code'], $tenantResult->errors());
    assert_true(in_array('forbidden_tenant_id', $codes, true));

    $sameId = CanonicalDocumentSchema::newSectionId();
    $dupSections = sb2_document([
        sb2_section([], ['id' => $sameId]),
        sb2_section([], ['id' => $sameId]),
    ]);
    $dupResult = DocumentValidator::validate($dupSections, $registry);
    assert_false($dupResult->isValid());

    $unknownBlockType = sb2_document([sb2_section([sb2_block($registry, 'core.hero', ['type' => 'core.does_not_exist'])])]);
    assert_false(DocumentValidator::validate($unknownBlockType, $registry)->isValid());

    // Nesting depth: MAX_NESTING_DEPTH (6) levels of containers around a hero is the last valid shape; one more fails.
    $nest = static function (int $containers) use ($registry): array {
        $node = sb2_block($registry, 'core.hero');
        for ($i = 0; $i < $containers; $i++) {
            $node = sb2_block($registry, 'core.container', ['children' => [$node]]);
        }
        return sb2_document([sb2_section([$node])]);
    };
    $maxDepth = CanonicalDocumentSchema::MAX_NESTING_DEPTH;
    assert_eq(6, $maxDepth, 'the approved nesting limit is 6');
    assert_true(DocumentValidator::validate($nest($maxDepth - 1), $registry)->isValid(), 'a hero at exactly the maximum depth is valid');
    assert_false(DocumentValidator::validate($nest($maxDepth), $registry)->isValid(), 'nesting beyond MAX_NESTING_DEPTH must fail');

    // max_blocks / max_sections overrides
    $twoBlocks = sb2_document([sb2_section([
        sb2_block($registry, 'core.hero'),
        sb2_block($registry, 'core.heading', ['props' => ['text' => 'Heading', 'level' => 'h2']]),
    ])]);
    $maxBlocksResult = DocumentValidator::validate($twoBlocks, $registry, ['max_blocks' => 1]);
    assert_false($maxBlocksResult->isValid(), 'max_blocks override must be enforced');

    $twoSections = sb2_document([sb2_section(), sb2_section()]);
    $maxSectionsResult = DocumentValidator::validate($twoSections, $registry, ['max_sections' => 1]);
    assert_false($maxSectionsResult->isValid(), 'max_sections override must be enforced');

    assert_throws(StudioValidationException::class, function () use ($missingSchemaVersion, $registry): void {
        DocumentValidator::assertValid($missingSchemaVersion, $registry);
    });
});

// ── 6. DependencyExtractor ───────────────────────────────────────────────────

unit('phase2 document domain: DependencyExtractor produces deduplicated, deterministic dependency records', function (): void {
    $registry = sb2_registry();
    $registry->register(new DeclarativeBlockDefinition(
        type: 'forms.embed',
        version: 1,
        label: 'Form Embed',
        category: 'forms',
        icon: 'form',
        schema: FieldSchema::define([['key' => 'form_id', 'type' => 'number', 'integer_only' => true, 'required' => true, 'default' => 1]]),
        requiredEntitlement: 'forms',
        allowedBindingProviders: ['forms.submissions'],
    ));

    $hero = sb2_block($registry, 'core.hero', [
        'props' => ['heading' => 'Hi', 'eyebrow' => '', 'subheading' => '', 'primary_cta' => null, 'media' => ['media_id' => 42, 'alt' => 'x', 'focal_point' => [0.5, 0.5]], 'accent_token' => 'color.brand'],
    ]);
    $formBlock = sb2_block($registry, 'forms.embed', [
        'props'    => ['form_id' => 7],
        'bindings' => ['results' => ['provider' => 'forms.submissions', 'params' => [], 'mapping' => []]],
    ]);
    $section = sb2_section([$hero, $formBlock], ['global_ref' => 'partial.shared_header', 'layout' => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), ['background_token' => 'surface.accent'])]);

    $doc = sb2_document([$section]);
    $doc['settings']['token_group'] = 'seasonal';
    $doc['seo']['og_image_media_id'] = 99;

    $normalized = DocumentNormalizer::normalize($doc, $registry);
    $deps = DependencyExtractor::extract($normalized, $registry);

    $signatures = array_map(static fn($d): string => $d->uniqueSignature(), $deps);

    assert_true(in_array(DependencyExtractor::ROOT_NODE_ID . '|token_group|seasonal', $signatures, true));
    assert_true(in_array(DependencyExtractor::ROOT_NODE_ID . '|media|99', $signatures, true));
    assert_true(in_array($section['id'] . '|partial|partial.shared_header', $signatures, true));
    assert_true(in_array($section['id'] . '|token_group|surface.accent', $signatures, true));

    $mediaFound = false;
    $tokenFound = false;
    $moduleFound = false;
    $entityFound = false;
    foreach ($deps as $d) {
        if ($d->dependencyType === 'media' && $d->dependencyKey === '42') {
            $mediaFound = true;
        }
        if ($d->dependencyType === 'token_group' && $d->dependencyKey === 'color.brand') {
            $tokenFound = true;
        }
        if ($d->dependencyType === 'module' && $d->dependencyKey === 'forms') {
            $moduleFound = true;
        }
        if ($d->dependencyType === 'entity' && $d->dependencyKey === 'forms.submissions') {
            $entityFound = true;
        }
    }
    assert_true($mediaFound, 'hero media_ref dependency must be extracted');
    assert_true($tokenFound, 'hero accent_token dependency must be extracted');
    assert_true($moduleFound, 'forms.embed required entitlement must be extracted as a module dependency');
    assert_true($entityFound, 'forms.embed binding provider must be extracted as an entity dependency');

    // Deduplication: running extraction twice on the same document must produce the same count.
    $depsAgain = DependencyExtractor::extract($normalized, $registry);
    assert_eq(count($deps), count($depsAgain));
});

// ── 7. LegacyDocumentConverter ───────────────────────────────────────────────

unit('phase2 document domain: LegacyDocumentConverter produces a schema "1.0" document that validates', function (): void {
    $registry = sb2_registry();

    $v1 = [
        'type'     => 'page',
        'template' => 'Default',
        'seo'      => ['title' => 'Legacy Page', 'description' => 'A legacy page.'],
        'sections' => [
            [
                'id'     => 'legacy-sec-1',
                'name'   => 'Intro',
                'blocks' => [
                    ['id' => 'legacy-blk-1', 'type' => 'hero', 'props' => ['heading' => 'Legacy Heading']],
                ],
            ],
        ],
    ];
    $converted = LegacyDocumentConverter::convertToCanonicalV1($v1, ['hero' => 'core.hero']);
    assert_eq('1.0', $converted['schema_version']);
    assert_eq('default', $converted['template_key']);

    $normalized = DocumentNormalizer::normalize($converted, $registry);
    $result = DocumentValidator::validate($normalized, $registry);
    assert_true($result->isValid(), 'converted v1 legacy document must validate: ' . json_encode($result->errors()));

    $v2 = [
        'version' => 2,
        'header'  => ['type' => 'landing', 'template' => 'promo', 'seo' => ['title' => 'V2 Page']],
        'sections' => [
            ['id' => 's1', 'label' => 'Hero Area', 'blocks' => [['id' => 'b1', 'type' => 'core.hero', 'props' => ['heading' => 'V2 Heading']]]],
        ],
    ];
    $convertedV2 = LegacyDocumentConverter::convertToCanonicalV1($v2);
    assert_eq('landing', $convertedV2['document_type']);
    assert_eq('promo', $convertedV2['template_key']);
    $normalizedV2 = DocumentNormalizer::normalize($convertedV2, $registry);
    assert_true(DocumentValidator::validate($normalizedV2, $registry)->isValid());
});

// ── 8. DocumentOperation + DocumentOperationApplier ─────────────────────────

unit('phase2 document domain: DocumentOperation rejects unknown ops and missing payload keys', function (): void {
    assert_throws(StudioValidationException::class, function (): void {
        new DocumentOperation('not_a_real_op', []);
    });
    assert_throws(StudioValidationException::class, function (): void {
        new DocumentOperation(DocumentOperation::OP_REMOVE_SECTION, []);
    }, 'missing required payload key must fail closed');

    $op = DocumentOperation::fromArray(['op' => DocumentOperation::OP_REMOVE_SECTION, 'payload' => ['section_id' => 'sec_x']]);
    assert_eq(DocumentOperation::OP_REMOVE_SECTION, $op->op);
    assert_eq(['section_id' => 'sec_x'], $op->payload);
});

unit('phase2 document domain: DocumentOperationApplier applies section/block mutations and fails closed', function (): void {
    $registry = sb2_registry();
    $doc = sb2_document();

    // insert_section
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_SECTION, ['index' => 0, 'section' => ['label' => 'First']]), $registry);
    assert_eq(1, count($doc['sections']));
    $sectionId = $doc['sections'][0]['id'];
    assert_true((bool) preg_match(CanonicalDocumentSchema::SECTION_ID_PATTERN, $sectionId));

    // insert_block into the section
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, [
        'parent_id' => $sectionId,
        'index'     => 0,
        'block'     => ['type' => 'core.heading', 'props' => ['text' => 'Hi', 'level' => 'h2']],
    ]), $registry);
    assert_eq(1, count($doc['sections'][0]['blocks']));
    $blockId = $doc['sections'][0]['blocks'][0]['id'];
    assert_eq('Hi', $doc['sections'][0]['blocks'][0]['props']['text']);

    // insert_block with unknown type must fail closed
    assert_throws(StudioValidationException::class, function () use ($doc, $sectionId, $registry): void {
        DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, [
            'parent_id' => $sectionId,
            'index'     => 0,
            'block'     => ['type' => 'core.does_not_exist'],
        ]), $registry);
    });

    // insert_block into a non-child-capable block must fail closed
    assert_throws(StudioValidationException::class, function () use ($doc, $blockId, $registry): void {
        DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, [
            'parent_id' => $blockId, // core.heading does not allow children
            'index'     => 0,
            'block'     => ['type' => 'core.button', 'props' => ['link' => ['label' => 'Go', 'href' => '/go']]],
        ]), $registry);
    });

    // update_block_props (whole-value replace)
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_UPDATE_BLOCK_PROPS, [
        'block_id' => $blockId,
        'props'    => ['text' => 'Updated', 'level' => 'h3'],
    ]), $registry);
    assert_eq('Updated', $doc['sections'][0]['blocks'][0]['props']['text']);
    assert_eq('h3', $doc['sections'][0]['blocks'][0]['props']['level']);

    // insert a second section, then move_section
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_SECTION, ['index' => 1, 'section' => ['label' => 'Second']]), $registry);
    $secondSectionId = $doc['sections'][1]['id'];
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_MOVE_SECTION, ['section_id' => $secondSectionId, 'to_index' => 0]), $registry);
    assert_eq($secondSectionId, $doc['sections'][0]['id']);

    // move_block across sections
    $sourceSectionId = $doc['sections'][1]['id']; // originally "First"
    $targetSectionId = $doc['sections'][0]['id']; // "Second", now moved to index 0
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_MOVE_BLOCK, [
        'block_id'  => $blockId,
        'parent_id' => $targetSectionId,
        'index'     => 0,
    ]), $registry);
    assert_eq(0, count($doc['sections'][1]['blocks']), 'block must be removed from its original section');
    assert_eq(1, count($doc['sections'][0]['blocks']), 'block must now live in the target section');
    assert_eq($blockId, $doc['sections'][0]['blocks'][0]['id']);
    assert_eq('Updated', $doc['sections'][0]['blocks'][0]['props']['text'], 'moved block must retain its existing props, not reset to defaults');

    // remove_block
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_REMOVE_BLOCK, ['block_id' => $blockId]), $registry);
    assert_eq(0, count($doc['sections'][0]['blocks']));

    // remove_section
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_REMOVE_SECTION, ['section_id' => $sourceSectionId]), $registry);
    assert_eq(1, count($doc['sections']));

    // operation targeting a nonexistent id must fail closed
    assert_throws(StudioValidationException::class, function () use ($doc, $registry): void {
        DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_REMOVE_BLOCK, ['block_id' => 'blk_does_not_exist_000000']), $registry);
    });

    // update_settings / update_seo shallow merge
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_UPDATE_SETTINGS, ['settings' => ['container_width' => 'narrow']]), $registry);
    assert_eq('narrow', $doc['settings']['container_width']);
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_UPDATE_SEO, ['seo' => ['title' => 'New Title']]), $registry);
    assert_eq('New Title', $doc['seo']['title']);

    // The final mutated document must still be a valid, normalizable canonical document.
    $normalized = DocumentNormalizer::normalize($doc, $registry);
    assert_true(DocumentValidator::validate($normalized, $registry)->isValid());
});

unit('phase2 document domain: DocumentOperationApplier nests blocks into a child-capable container', function (): void {
    $registry = sb2_registry();
    $seedSection = sb2_section();
    $doc = sb2_document([$seedSection]);
    $containerSectionId = $seedSection['id'];

    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, [
        'parent_id' => $containerSectionId,
        'index'     => 0,
        'block'     => ['type' => 'core.container'],
    ]), $registry);
    $containerId = $doc['sections'][0]['blocks'][0]['id'];

    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, [
        'parent_id' => $containerId,
        'index'     => 0,
        'block'     => ['type' => 'core.heading', 'props' => ['text' => 'Nested', 'level' => 'h4']],
    ]), $registry);

    assert_eq(1, count($doc['sections'][0]['blocks'][0]['children']));
    assert_eq('Nested', $doc['sections'][0]['blocks'][0]['children'][0]['props']['text']);

    $normalized = DocumentNormalizer::normalize($doc, $registry);
    assert_true(DocumentValidator::validate($normalized, $registry)->isValid());
});

// ── 9. PageAddress ────────────────────────────────────────────────────────────

unit('phase2 document domain: PageAddress construction, fromRow/toArray, and fail-closed invariants', function (): void {
    $row = [
        'id'                       => 5,
        'uuid'                     => '11111111-1111-4111-8111-111111111111',
        'title'                    => 'Home',
        'slug'                     => 'home',
        'page_type'                => 'page',
        'status'                   => 'draft',
        'route_mode'               => 'homepage',
        'active_draft_revision_id' => 10,
        'published_revision_id'    => null,
        'published_at'             => null,
        'scheduled_for'            => null,
    ];
    $address = PageAddress::fromRow($row);
    assert_eq(5, $address->id);
    assert_true($address->hasWorkingDraft());
    assert_false($address->isPublished());
    assert_true($address->hasUnpublishedChanges());

    $arr = $address->toArray();
    assert_eq('home', $arr['slug']);
    assert_eq(10, $arr['active_draft_revision_id']);

    $renamed = $address->withSlug('homepage-v2')->withTitle('New Home');
    assert_eq('homepage-v2', $renamed->slug);
    assert_eq('New Home', $renamed->title);
    assert_eq($address->id, $renamed->id, 'with*() must preserve identity');

    foreach ([
        ['uuid' => 'not-a-uuid'],
        ['title' => ''],
        ['slug' => 'Not Valid Slug!'],
        ['page_type' => 'not_a_type'],
        ['status' => 'not_a_status'],
        ['route_mode' => 'not_a_mode'],
    ] as $bad) {
        assert_throws(StudioValidationException::class, function () use ($row, $bad): void {
            PageAddress::fromRow(array_merge($row, $bad));
        }, 'invalid PageAddress field must fail closed: ' . json_encode($bad));
    }
});

// ── 10. StudioTemplate ────────────────────────────────────────────────────────

unit('phase2 document domain: StudioTemplate construction, fromRow decoding, and fail-closed invariants', function (): void {
    $doc = CanonicalDocumentSchema::emptyDocument('section_preset', 'default', 'Preset');
    $row = [
        'id'                 => 3,
        'uuid'               => '22222222-2222-4222-8222-222222222222',
        'template_key'       => 'hero-minimal',
        'template_type'      => 'section_preset',
        'category'           => 'hero',
        'name'               => 'Minimal Hero',
        'description'        => null,
        'thumbnail_media_id' => null,
        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json'      => CanonicalJson::encode($doc),
        'is_system'          => 0,
    ];

    $template = StudioTemplate::fromRow($row);
    assert_eq('hero-minimal', $template->templateKey);
    assert_eq($doc['document_type'], $template->document['document_type']);
    assert_false($template->isSystem);

    $arr = $template->toArray();
    assert_eq('hero-minimal', $arr['template_key']);

    assert_throws(StudioValidationException::class, function () use ($row): void {
        StudioTemplate::fromRow(array_merge($row, ['template_type' => 'not_a_type']));
    });
    assert_throws(StudioValidationException::class, function () use ($row): void {
        StudioTemplate::fromRow(array_merge($row, ['template_key' => 'Not A Valid Key!']));
    });
    assert_throws(StudioValidationException::class, function () use ($row): void {
        StudioTemplate::fromRow(array_merge($row, ['name' => '']));
    });
});

if (!empty($studioP2UnitStandalone)) {
    exit(unit_summary());
}
