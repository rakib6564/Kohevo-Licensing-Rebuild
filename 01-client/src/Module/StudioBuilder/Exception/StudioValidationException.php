<?php
/**
 * Kohevo Studio (studio-builder) — Canonical Document & Field Validation Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

final class StudioValidationException extends StudioException
{
    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Kohevo Studio canonical document validation failed.',
    ) {
        parent::__construct($message, 'STUDIO_VALIDATION_FAILED', 422, ['errors' => $errors]);
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
