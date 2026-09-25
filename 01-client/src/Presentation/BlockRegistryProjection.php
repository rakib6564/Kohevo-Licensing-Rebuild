<?php
declare(strict_types=1);

namespace Slate\Presentation;

/**
 * Converts server block definitions into a client-safe builder palette.
 * Renderers, closures, PHP class names, and arbitrary metadata never cross this boundary.
 */
final class BlockRegistryProjection
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function project(BlockRegistry $registry): array
    {
        $out = [];
        foreach ($registry->all() as $type => $block) {
            $schema = $block->schema();
            $out[] = [
                'type' => $type,
                'version' => '1.0.0',
                'label' => self::label($type),
                'category' => 'Content',
                'capabilities' => [
                    'responsive' => self::hasResponsiveField($schema),
                    'nested' => false,
                    'dynamic' => false,
                    'serverOnly' => false,
                ],
                'permissions' => [],
                'fields' => array_values($schema->fields()),
                'defaults' => $schema->defaults(),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string)$a['type'], (string)$b['type']));
        return $out;
    }

    private static function label(string $type): string
    {
        return ucwords(str_replace(['-', '_', '.'], ' ', $type));
    }

    private static function hasResponsiveField(FieldSchema $schema): bool
    {
        foreach ($schema->fields() as $field) {
            if (($field['responsive'] ?? false) === true) return true;
        }
        return false;
    }
}
