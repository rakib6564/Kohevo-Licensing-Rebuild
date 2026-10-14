<?php
/**
 * Kohevo Studio (studio-builder) — what the public pay endpoint does with a posted payment button.
 *
 * Pure flow with its collaborators passed in, so every outcome is testable without Stripe, a database or HTTP: only a
 * POST, only when Stripe is set up, only within the visitor's limit, only an offer that verifies (see PaymentOffer),
 * only one made for this tenant. The Stripe session is created from the offer's own terms, never from anything else in
 * the request, and the visitor is sent on with a 303 to the checkout URL Stripe returned.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Payment;

final class PayRequest
{
    /**
     * @param array<string, mixed> $post
     * @param array{tenantId: int, siteUrl: string, referer: string, visitor: string, configured: bool} $env
     * @param callable(string, string, string): bool $verifier `slate_sign_equals`
     * @param callable(int, string, string, string, string): string $checkout (cents, currency, name, successUrl, cancelUrl) -> hosted checkout URL; throws on failure
     * @param callable(string): bool $allow per-visitor limit
     * @return array{status: int, location?: string, message?: string}
     */
    public static function handle(string $method, array $post, array $env, callable $verifier, callable $checkout, callable $allow): array
    {
        if (strtoupper($method) !== 'POST') {
            return ['status' => 405, 'message' => 'Use the payment button on the page.'];
        }
        if (!$env['configured']) {
            return ['status' => 503, 'message' => 'Online payment is not available right now.'];
        }
        if (!$allow($env['visitor'])) {
            return ['status' => 429, 'message' => 'Too many attempts. Please wait a minute and try again.'];
        }
        $offer = PaymentOffer::fromPost($post, $verifier);
        if ($offer === null || $offer['t'] !== $env['tenantId']) {
            return ['status' => 400, 'message' => 'This payment button is out of date. Reload the page and try again.'];
        }

        $site = rtrim($env['siteUrl'], '/');
        $success = $site . ($offer['s'] !== '' ? '/' . $offer['s'] : '/');
        $cancel = self::sameSite($env['referer'], $site) ? $env['referer'] : $site . '/';
        $name = $offer['d'] !== '' ? $offer['d'] : 'Payment';

        try {
            $url = $checkout($offer['a'], $offer['c'], $name, $success, $cancel);
        } catch (\Throwable) {
            return ['status' => 502, 'message' => 'Could not start the payment. Please try again.'];
        }
        if (preg_match('~^https://[A-Za-z0-9.-]+\.stripe\.com/~', $url) !== 1) {
            return ['status' => 502, 'message' => 'Could not start the payment. Please try again.'];
        }
        return ['status' => 303, 'location' => $url];
    }

    /** A URL on this site (same origin as `$site`): used so the "cancel" return can never be sent anywhere else. */
    public static function sameSite(string $url, string $site): bool
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20"<>\\\\^`{|}]/', $url) === 1) {
            return false;
        }
        $a = parse_url($url);
        $b = parse_url($site);
        return is_array($a) && is_array($b)
            && strtolower((string) ($a['scheme'] ?? '')) === strtolower((string) ($b['scheme'] ?? '-'))
            && strtolower((string) ($a['host'] ?? '')) === strtolower((string) ($b['host'] ?? '-'))
            && (int) ($a['port'] ?? 0) === (int) ($b['port'] ?? 0);
    }
}
