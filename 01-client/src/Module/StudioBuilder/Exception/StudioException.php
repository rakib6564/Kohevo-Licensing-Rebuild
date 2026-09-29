<?php
/**
 * Kohevo Studio (studio-builder) — Base Domain & Application Exception.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Exception;

class StudioException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'STUDIO_ERROR',
        private readonly int $httpStatus = 400,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
