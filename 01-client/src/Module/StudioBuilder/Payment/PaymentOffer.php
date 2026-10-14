<?php
/**
 * Kohevo Studio (studio-builder) — the signed offer behind a `payment.button` block.
 *
 * A published Studio page is anonymous and cacheable, so the button's form carries its own terms (amount, currency,
 * what is being paid for, where to return to) as hidden fields, signed with the platform's application secret. The
 * public pay endpoint accepts only an offer whose signature it can reproduce: a visitor cannot lower the price or
 * change the currency by editing the form, because only the server can sign a new one. An offer is the price the
 * visitor was legitimately shown; it stays valid until the secret changes, which is exactly how a cached page behaves.
 *
 * Pure: the signer is injected (the platform's `slate_sign`), so it is testable without a configured secret.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Payment;

final class PaymentOffer
{
    public const SIGN_CONTEXT = 'studio-pay';

    /** Stripe's minimum charge is about 50 cents; the cap keeps a typo from becoming a six-figure checkout. */
    public const MIN_CENTS = 50;
    public const MAX_CENTS = 99_999_999;

    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'NZD'];

    public const MAX_DESCRIPTION = 120;

    /**
     * The terms of an offer from a block's props, or null when they cannot be offered (no amount, a bad currency, ...).
     *
     * @param array<string, mixed> $props
     * @return array{t: int, a: int, c: string, d: string, s: string}|null
     */
    public static function fromProps(array $props, int $tenantId): ?array
    {
        $amount = $props['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount)) {
            return null;
        }
        $cents = (int) round(((float) $amount) * 100);
        $currency = strtoupper(is_string($props['currency'] ?? null) ? trim($props['currency']) : 'USD');
        if ($cents < self::MIN_CENTS || $cents > self::MAX_CENTS || !in_array($currency, self::CURRENCIES, true) || $tenantId < 1) {
            return null;
        }
        $description = self::clean(is_string($props['description'] ?? null) ? $props['description'] : '', self::MAX_DESCRIPTION);
        $success = self::path(is_string($props['success_path'] ?? null) ? $props['success_path'] : '');
        return ['t' => $tenantId, 'a' => $cents, 'c' => $currency, 'd' => $description, 's' => $success];
    }

    /**
     * Whether `$offer` has the exact shape and limits of an offer (what the endpoint re-checks after the signature).
     *
     * @param array<string, mixed> $offer
     */
    public static function isWellFormed(array $offer): bool
    {
        return isset($offer['t'], $offer['a'], $offer['c'], $offer['d'], $offer['s'])
            && is_int($offer['t']) && $offer['t'] >= 1
            && is_int($offer['a']) && $offer['a'] >= self::MIN_CENTS && $offer['a'] <= self::MAX_CENTS
            && is_string($offer['c']) && in_array($offer['c'], self::CURRENCIES, true)
            && is_string($offer['d']) && mb_strlen($offer['d'], 'UTF-8') <= self::MAX_DESCRIPTION
            && is_string($offer['s']) && ($offer['s'] === '' || self::path($offer['s']) === $offer['s']);
    }

    /** The exact text that is signed: every term, in a fixed order, one per line. */
    public static function canonical(array $offer): string
    {
        return implode("\n", [(string) $offer['t'], (string) $offer['a'], (string) $offer['c'], (string) $offer['d'], (string) $offer['s']]);
    }

    /**
     * @param array{t: int, a: int, c: string, d: string, s: string} $offer
     * @param callable(string, string): ?string $signer `slate_sign` (context, value) -> signature or null when no secret is set
     */
    public static function sign(array $offer, callable $signer): ?string
    {
        return $signer(self::SIGN_CONTEXT, self::canonical($offer));
    }

    /**
     * The offer a posted form carries, or null when it is malformed or its signature does not verify.
     *
     * @param array<string, mixed> $post
     * @param callable(string, string, string): bool $verifier `slate_sign_equals` (context, value, candidate)
     * @return array{t: int, a: int, c: string, d: string, s: string}|null
     */
    public static function fromPost(array $post, callable $verifier): ?array
    {
        foreach (['t', 'a', 'c', 'd', 's', 'g'] as $key) {
            if (!isset($post[$key]) || !is_string($post[$key])) {
                return null;
            }
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/', $post['t']) !== 1 || preg_match('/^[1-9][0-9]{0,9}$/', $post['a']) !== 1) {
            return null;
        }
        $offer = ['t' => (int) $post['t'], 'a' => (int) $post['a'], 'c' => $post['c'], 'd' => $post['d'], 's' => $post['s']];
        if (!self::isWellFormed($offer) || !$verifier(self::SIGN_CONTEXT, self::canonical($offer), $post['g'])) {
            return null;
        }
        return $offer;
    }

    /** A one-line label: control characters and runs of space removed, cut to `$max` characters. */
    public static function clean(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text)));
        return mb_substr($text, 0, $max, 'UTF-8');
    }

    /** A same-site path such as `thank-you` or `shop/thanks`: letters, digits, `-`, `_` and `/`, no dots, no scheme, no `..`. */
    public static function path(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B/");
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9_\-]*(?:/[A-Za-z0-9][A-Za-z0-9_\-]*){0,5}$~', $path) === 1 && strlen($path) <= 120 ? $path : '';
    }

    /** `49.00 USD` for display. */
    public static function display(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2, '.', ',') . ' ' . $currency;
    }
}
