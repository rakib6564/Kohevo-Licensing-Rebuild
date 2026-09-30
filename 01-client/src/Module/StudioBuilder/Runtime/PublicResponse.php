<?php
/**
 * Kohevo Studio (studio-builder) — A response the public runtime wants sent.
 *
 * `null` from the runtime (not this class) means "not a Studio page": the
 * caller then renders the platform's ordinary 404 / landing, byte-for-byte
 * unchanged. A 500 here carries no body — the HTTP adapter renders the
 * platform's generic error page, never an internal message.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

final class PublicResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /** @param array<string, string> $headers */
    public static function ok(array $headers, string $body): self
    {
        return new self(200, $headers, $body);
    }

    /** @param array<string, string> $headers */
    public static function notModified(array $headers): self
    {
        return new self(304, $headers, '');
    }

    public static function error(): self
    {
        return new self(500, ['Cache-Control' => 'no-store'], '');
    }

    /**
     * The same page without a validator (Phase 9B): no ETag header. Used when
     * the body the ETag was computed over is not the body the client will get
     * (a later output buffer rewrites it), so the ETag must not be published.
     */
    public function withoutValidator(): self
    {
        $headers = $this->headers;
        unset($headers['ETag']);
        return new self($this->status, $headers, $this->body);
    }
}
