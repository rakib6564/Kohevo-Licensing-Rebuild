<?php
/**
 * Kohevo Studio (studio-builder) — Validation Result Value Object.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Schema;

final class ValidationResult
{
    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = [],
    ) {}

    public static function ok(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    public static function fromErrors(array $errors): self
    {
        return new self($errors === [], array_values($errors));
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array{path: string, code: string, message: string}
     */
    public static function issue(string $path, string $code, string $message): array
    {
        return [
            'path'    => $path,
            'code'    => $code,
            'message' => $message,
        ];
    }
}
