<?php
/**
 * Kohevo Studio (studio-builder) — Dependency Record Value Object.
 *
 * Maps directly to a row in `studiobuilder_dependencies`:
 * - `node_id` VARCHAR(64)
 * - `dependency_type` ENUM('media','module','entity','token_group','partial','form')
 * - `dependency_key` VARCHAR(191)
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Dependency;

final class DependencyRecord
{
    public const ALLOWED_TYPES = [
        'media',
        'module',
        'entity',
        'token_group',
        'partial',
        'form',
    ];

    public function __construct(
        public readonly string $nodeId,
        public readonly string $dependencyType,
        public readonly string $dependencyKey,
    ) {
        if ($nodeId === '' || strlen($nodeId) > 64) {
            throw new \InvalidArgumentException("Invalid dependency nodeId '{$nodeId}'.");
        }
        if (!in_array($dependencyType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported dependency_type '{$dependencyType}'.");
        }
        if ($dependencyKey === '' || strlen($dependencyKey) > 191) {
            throw new \InvalidArgumentException("Invalid dependency_key '{$dependencyKey}'.");
        }
    }

    public function uniqueSignature(): string
    {
        return "{$this->nodeId}|{$this->dependencyType}|{$this->dependencyKey}";
    }

    /**
     * @return array{node_id: string, dependency_type: string, dependency_key: string}
     */
    public function toArray(): array
    {
        return [
            'node_id'         => $this->nodeId,
            'dependency_type' => $this->dependencyType,
            'dependency_key'  => $this->dependencyKey,
        ];
    }
}
