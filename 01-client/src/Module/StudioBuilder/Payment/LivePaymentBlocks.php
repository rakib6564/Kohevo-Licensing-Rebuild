<?php
/**
 * Kohevo Studio (studio-builder) — the payment button a visitor's offer names, as it is LIVE now.
 *
 * The pay endpoint honours a signed offer only while a published Studio document of the tenant still holds that
 * `payment.button` block. The block may sit on a page, in a header or footer, a theme template or a global component:
 * all of them are Studio pages whose published revision is searched (not a draft, not an archived page).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Payment;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;

final class LivePaymentBlocks
{
    public const TYPE = 'payment.button';

    /** The props of the live `payment.button` with id `$blockId` in tenant `$tenantId`, or null. @return ?array<string, mixed> */
    public static function propsOf(int $tenantId, string $blockId): ?array
    {
        if ($tenantId < 1 || !PaymentOffer::isBlockId($blockId)) {
            return null;
        }
        $rows = Database::rows(
            "SELECT r.document_json
               FROM studiobuilder_pages p
               INNER JOIN studiobuilder_revisions r ON r.tenant_id = p.tenant_id AND r.id = p.published_revision_id
              WHERE p.tenant_id = ? AND p.status <> 'archived' AND r.document_json LIKE ?
              LIMIT 5",
            [$tenantId, '%"' . addcslashes($blockId, '%_\\') . '"%']
        );
        foreach ($rows as $row) {
            $document = json_decode((string) ($row['document_json'] ?? ''), true);
            $props = is_array($document) ? self::findInDocument($document, $blockId) : null;
            if ($props !== null) {
                return $props;
            }
        }
        return null;
    }

    /**
     * The props of the `payment.button` block `$blockId` in a canonical document, or null.
     *
     * @param array<string, mixed> $document
     * @return ?array<string, mixed>
     */
    public static function findInDocument(array $document, string $blockId): ?array
    {
        $containers = DocumentUpgrader::structure($document)['containers'] ?? null;
        $stack = is_array($containers) ? array_values($containers) : [];
        while ($stack !== []) {
            $block = array_pop($stack);
            if (!is_array($block)) {
                continue;
            }
            if (($block['id'] ?? null) === $blockId) {
                return ($block['type'] ?? null) === self::TYPE && is_array($block['props'] ?? null) ? $block['props'] : null;
            }
            if (is_array($block['children'] ?? null)) {
                array_push($stack, ...array_values($block['children']));
            }
        }
        return null;
    }
}
