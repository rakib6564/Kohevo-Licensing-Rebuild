<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — data integrity (2026-10-26 builder audit, step 3).
 *
 * Autoloader only. A document is accepted only when its stored form can be read back.
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
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

/** A page with one section holding the given blocks. */
function sba3_doc(array $blocks): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default');
    $doc = DocumentOperationApplier::applyOne($doc, new DocumentOperation('insert_section', ['index' => 0, 'section' => ['label' => 'Main']]), BlockRegistry::withAllCoreBlocks());
    $doc['sections'][0]['blocks'] = $blocks;
    return $doc;
}

function sba3_text(string $content, array $extra = []): array
{
    return array_merge([
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.text', 'version' => 1, 'props' => ['content' => $content], 'style' => [],
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
    ], $extra);
}

/** The validation codes a call raised, or [] when it passed. */
function sba3_codes(callable $fn): array
{
    try {
        $fn();
    } catch (StudioValidationException $e) {
        return array_column($e->errors(), 'code');
    }
    return [];
}

unit('readable documents: a deep value under a responsive key is refused before it is stored', function (): void {
    $reg  = BlockRegistry::withAllCoreBlocks();
    $deep = 'x';
    for ($i = 0; $i < 30; $i++) {
        $deep = ['n' => $deep];
    }
    $doc = sba3_doc([sba3_text('Hi', ['responsive' => ['tablet' => ['extra' => $deep]]])]);

    assert_true(DocumentValidator::validate($doc, $reg)->isValid(), 'the validator alone lets it through (the hole)');
    assert_eq(['document_too_deep'], sba3_codes(static fn() => DocumentNormalizer::validateAndNormalize($doc, $reg)), 'normalizing refuses it');
});

unit('readable documents: a page under 1 MiB as sent but over it once saved is refused', function (): void {
    $reg    = BlockRegistry::withAllCoreBlocks();
    $blocks = [];
    for ($i = 0; $i < 250; $i++) {
        $blocks[] = sba3_text(str_repeat('a', 3850)); // about 1 MB as sent; defaults add about 235 bytes a block
    }
    $doc = sba3_doc($blocks);

    assert_true(strlen(json_encode($doc)) < CanonicalDocumentSchema::MAX_DOCUMENT_BYTES, 'it is under the limit as sent');
    assert_true(DocumentValidator::validate($doc, $reg)->isValid(), 'the validator alone lets it through');
    assert_eq(['document_too_large'], sba3_codes(static fn() => DocumentNormalizer::validateAndNormalize($doc, $reg)), 'normalizing refuses it');
});

unit('readable documents: an ordinary page still saves and reads back unchanged', function (): void {
    $reg  = BlockRegistry::withAllCoreBlocks();
    $norm = DocumentNormalizer::validateAndNormalize(sba3_doc([sba3_text('Hello', ['responsive' => ['mobile' => ['hide' => true]]])]), $reg);
    assert_eq($norm, CanonicalJson::decode(CanonicalJson::encode($norm)), 'the stored form decodes to the same document');
});
