<?php
/**
 * Kohevo Studio — GoogleTransport over curl. Redirects are refused, so a bearer token only ever reaches the address asked for.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class CurlGoogleTransport implements GoogleTransport
{
    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new GoogleApiException(GoogleApiException::UNAVAILABLE, 'curl is not available');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        if ($answer === false || !is_string($answer)) {
            // The curl error text can repeat the address, which holds the PageSpeed key.
            throw new GoogleApiException(GoogleApiException::UNAVAILABLE, 'curl error ' . $errno);
        }
        return ['status' => $status, 'body' => $answer];
    }
}
