<?php
/**
 * Kohevo Studio (studio-builder) — per-site block availability (the Element Manager).
 *
 * A site's administrators may switch individual block types off. A disabled type is no longer offered in the
 * Add panel and cannot be INSERTED any more (an `insert_block` operation naming it, or carrying it inside a
 * preset subtree, is refused). It is never removed from the registry or from stored documents: blocks of that
 * type already on a page keep rendering and stay editable, so switching an element off cannot break a page.
 *
 * The list is one tenant setting (`studio_disabled_blocks`, a JSON array of type keys). Reading and writing
 * go through two closures so the class is testable without a database.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;

final class BlockAvailability
{
    public const SETTING_KEY = 'studio_disabled_blocks';

    /** More than this many block types can never be registered, so a longer list is not a real selection. */
    public const MAX_TYPES = 200;

    /** @var \Closure(int): mixed */
    private \Closure $read;
    /** @var \Closure(int, string): void */
    private \Closure $write;

    public function __construct(?\Closure $read = null, ?\Closure $write = null)
    {
        $this->read  = $read  ?? static fn(int $tenantId): mixed => Database::setting(self::SETTING_KEY, $tenantId);
        $this->write = $write ?? static function (int $tenantId, string $json): void {
            Database::setSetting(self::SETTING_KEY, $json, $tenantId);
        };
    }

    /**
     * The disabled block types of one tenant, sorted. A missing, corrupt or non-list value reads as "nothing disabled".
     *
     * @return list<string>
     */
    public function disabled(int $tenantId): array
    {
        $raw = ($this->read)($tenantId);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? self::clean($decoded) : [];
    }

    /**
     * Replace the tenant's list. Every entry must be a registered block type; the stored list is de-duplicated and sorted.
     *
     * @param array<mixed> $types
     * @return list<string>
     */
    public function save(int $tenantId, array $types, BlockRegistry $registry): array
    {
        if (!array_is_list($types) || count($types) > self::MAX_TYPES) {
            throw new StudioValidationException([['path' => '$.disabled', 'code' => 'invalid_field', 'message' => 'disabled must be a list of block types.']]);
        }
        $issues = [];
        foreach ($types as $i => $type) {
            if (!is_string($type) || !$registry->has($type)) {
                $issues[] = ['path' => '$.disabled[' . $i . ']', 'code' => 'unknown_block_type', 'message' => 'Only registered block types can be switched off.'];
            }
        }
        if ($issues !== []) {
            throw new StudioValidationException($issues);
        }
        $clean = self::clean($types);
        ($this->write)($tenantId, json_encode($clean, JSON_THROW_ON_ERROR));
        return $clean;
    }

    /**
     * The disabled types that an operation list would INSERT (directly or inside a preset subtree).
     *
     * @param list<DocumentOperation> $operations
     * @param list<string>            $disabled
     * @return list<string>
     */
    public static function blockedBy(array $operations, array $disabled): array
    {
        if ($disabled === []) {
            return [];
        }
        $blocked = [];
        $visit = static function (mixed $block, int $depth) use (&$visit, $disabled, &$blocked): void {
            if (!is_array($block) || $depth > 32) {
                return;
            }
            $type = $block['type'] ?? null;
            if (is_string($type) && in_array($type, $disabled, true)) {
                $blocked[$type] = true;
            }
            foreach (is_array($block['children'] ?? null) ? $block['children'] : [] as $child) {
                $visit($child, $depth + 1);
            }
        };
        foreach ($operations as $operation) {
            if ($operation->op === DocumentOperation::OP_INSERT_BLOCK) {
                $visit($operation->payload['block'] ?? null, 0);
            }
        }
        $types = array_keys($blocked);
        sort($types, SORT_STRING);
        return $types;
    }

    /**
     * @param array<mixed> $types
     * @return list<string>
     */
    private static function clean(array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            if (is_string($type) && preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $type) === 1) {
                $out[$type] = true;
            }
        }
        $list = array_keys($out);
        sort($list, SORT_STRING);
        return $list;
    }
}
