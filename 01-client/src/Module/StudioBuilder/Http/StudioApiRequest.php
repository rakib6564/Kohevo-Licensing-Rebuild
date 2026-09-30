<?php
/**
 * Kohevo Studio (studio-builder) — One builder API request, as plain data.
 *
 * Built by the thin HTTP adapter (`plugins/studio-builder/admin/api.php`)
 * from the real request, or directly by tests. The CSRF verdict is computed
 * by the platform's own `csrf_verify()` in the adapter and passed in as a
 * boolean, so the controller enforces it without touching the session.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Http;

final class StudioApiRequest
{
    /**
     * @param array<string, mixed> $query decoded query-string parameters
     */
    public function __construct(
        public readonly string $method,
        public readonly string $action,
        public readonly array $query = [],
        public readonly string $body = '',
        public readonly string $contentType = '',
        public readonly bool $csrfValid = false,
        public readonly ?string $fetchSite = null,
    ) {}

    public static function fromGlobals(bool $csrfValid, int $maxBodyBytes): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = '';
        if ($method === 'POST') {
            // Read one byte past the limit so an oversized body is detected
            // without buffering an arbitrarily large request.
            $body = (string) file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        }
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;

        return new self(
            $method,
            is_string($_GET['action'] ?? null) ? $_GET['action'] : '',
            array_diff_key($_GET, ['action' => true]),
            $body,
            (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
            $csrfValid,
            is_string($fetchSite) ? strtolower($fetchSite) : null,
        );
    }
}
