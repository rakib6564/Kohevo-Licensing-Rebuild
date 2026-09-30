<?php
/**
 * Kohevo Studio (studio-builder) — A refused or failed Studio MCP tool call.
 *
 * The MCP gateway turns any exception into an `isError` tool result whose
 * text is the exception message. An AI client needs STRUCTURED information
 * (was it a stale revision? which revision is current? which field was
 * invalid?), so the message is a compact JSON object with a safe, fixed
 * error code, an HTTP-like status, a fixed human sentence and bounded
 * details — never an internal message, class name, SQL or path. The audit
 * log stores the same string; it contains no secret.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Mcp;

final class StudioMcpToolException extends \RuntimeException
{
    /** Safe messages per public error code (the same vocabulary as the builder API). */
    public const MESSAGES = [
        'authentication_error' => 'The caller is not authenticated for Studio.',
        'authorization_error'  => 'The caller does not have permission to do this in Studio.',
        'entitlement_error'    => 'Kohevo Studio is not available on this site.',
        'not_found'            => 'This page, revision, template or component was not found.',
        'concurrency_conflict' => 'The page was changed since the given expected_revision_id. Re-read the page and decide again; nothing was overwritten.',
        'validation_error'     => 'The request was rejected because it is not valid.',
        'payload_too_large'    => 'The request is too large.',
        'rate_limited'         => 'Too many Studio requests. Try again shortly.',
        'tool_not_available'   => 'This Studio tool is not available to the caller.',
        'server_error'         => 'Studio could not complete this request.',
    ];

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $details = [],
    ) {
        $code = isset(self::MESSAGES[$errorCode]) ? $errorCode : 'server_error';
        $payload = ['error' => $code, 'status' => $status, 'message' => self::MESSAGES[$code]];
        if ($details !== []) {
            $payload['details'] = $details;
        }
        parent::__construct((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status);
    }
}
