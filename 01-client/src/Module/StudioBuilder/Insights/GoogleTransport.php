<?php
/**
 * Kohevo Studio — one HTTP exchange with Google. Tests answer with arrays; production uses CurlGoogleTransport.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

interface GoogleTransport
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     * @throws GoogleApiException when no answer came back
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array;
}
