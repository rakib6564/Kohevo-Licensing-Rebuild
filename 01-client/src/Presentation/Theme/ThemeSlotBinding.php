<?php
declare(strict_types=1);

namespace Slate\Presentation\Theme;

use InvalidArgumentException;

final class ThemeSlotBinding implements \JsonSerializable
{
    public function __construct(
        public readonly ?int $headerBlockId = null,
        public readonly ?int $footerBlockId = null,
    ) {
        foreach ([$headerBlockId, $footerBlockId] as $id) {
            if ($id !== null && $id < 1) throw new InvalidArgumentException('Theme global block IDs must be positive integers.');
        }
    }

    public static function fromArray(array $value): self
    {
        return new self(self::id($value['header_block_id'] ?? null), self::id($value['footer_block_id'] ?? null));
    }

    public function jsonSerialize(): array
    {
        return ['header_block_id' => $this->headerBlockId, 'footer_block_id' => $this->footerBlockId];
    }

    private static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && ctype_digit($value) && (int)$value > 0) return (int)$value;
        throw new InvalidArgumentException('Theme global block IDs must be positive integers.');
    }
}
