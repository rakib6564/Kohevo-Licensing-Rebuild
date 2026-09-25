<?php
declare(strict_types=1);

namespace Slate\Presentation;

use InvalidArgumentException;

final class GlobalReferenceResolver
{
    public const MAX_DEPTH = 8;

    /** @param array<string,array<string,mixed>> $globals @param array<string,mixed> $block @return array<string,mixed> */
    public static function resolve(array $block, array $globals): array
    {
        $seen = [];
        $current = $block;
        for ($depth = 0; $depth <= self::MAX_DEPTH; $depth++) {
            $alias = self::alias($current);
            if ($alias === null) return $current;
            if (isset($seen[$alias])) throw new InvalidArgumentException('Global block reference cycle detected.');
            if (!isset($globals[$alias]) || !is_array($globals[$alias])) throw new InvalidArgumentException('Global block reference does not exist.');
            $seen[$alias] = true;
            $current = $globals[$alias];
        }
        throw new InvalidArgumentException('Global block reference depth is too great.');
    }

    /** @param array<string,mixed> $block */
    private static function alias(array $block): ?string
    {
        $ref = $block['$ref'] ?? ($block['props']['$ref'] ?? null);
        if ($ref === null) $ref = $block['savedAs'] ?? null;
        if ($ref === null) return null;
        if (!is_string($ref) || !preg_match('/^[a-z][a-z0-9-]{0,95}$/', $ref)) throw new InvalidArgumentException('Global block alias is invalid.');
        return $ref;
    }
}
