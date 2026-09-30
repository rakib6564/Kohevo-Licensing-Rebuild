<?php
/**
 * Kohevo Studio (studio-builder) — A builder API response: status + JSON envelope.
 *
 *   success: {"ok": true,  "data": {...}}
 *   failure: {"ok": false, "error": {"code": "<safe code>", "message": "...", "details": {...}}}
 *
 * Always private/no-store and never indexed. Nothing internal (exception
 * class, message, trace, SQL, file path) is ever placed in a failure body.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

use Slate\Module\StudioBuilder\Runtime\StudioRequestId;

final class StudioApiResponse
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $extraHeaders
     */
    private function __construct(
        public readonly int $status,
        public readonly array $payload,
        public readonly array $extraHeaders = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data, int $status = 200): self
    {
        return new self($status, ['ok' => true, 'data' => $data]);
    }

    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['ok' => false, 'error' => $error], $headers);
    }

    public function isOk(): bool
    {
        return ($this->payload['ok'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return is_array($this->payload['data'] ?? null) ? $this->payload['data'] : [];
    }

    public function errorCode(): ?string
    {
        return is_array($this->payload['error'] ?? null) ? (string) $this->payload['error']['code'] : null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        // A failure carries the request id (Phase 9D) so a report maps to the server log / audit row.
        $trace = $this->isOk() ? [] : ['X-Request-Id' => StudioRequestId::current()];
        return $trace + [
            'Content-Type'           => 'application/json; charset=utf-8',
            'Cache-Control'          => 'private, no-store, max-age=0',
            'Pragma'                 => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'Referrer-Policy'        => 'no-referrer',
        ] + $this->extraHeaders;
    }

    public function body(): string
    {
        $json = json_encode($this->payload, self::JSON_FLAGS);
        return $json === false ? '{"ok":false,"error":{"code":"server_error","message":"The server could not complete this request."}}' : $json;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers() as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body();
    }
}
