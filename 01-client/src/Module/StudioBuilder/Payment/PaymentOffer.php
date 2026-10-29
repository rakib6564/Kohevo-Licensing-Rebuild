<?php
/**
 * Kohevo Studio (studio-builder) — the signed offer behind a `payment.button` block.
 *
 * A published Studio page is anonymous and cacheable, so the button's form carries its own terms (amount, currency,
 * what is being paid for, where to return to) as hidden fields, signed with the platform's application secret. The
 * public pay endpoint accepts only an offer whose signature it can reproduce: a visitor cannot lower the price or
 * change the currency by editing the form, because only the server can sign a new one.
 *
 * An offer also names the block that showed it and when it expires, and the endpoint only honours it while a PUBLISHED
 * Studio document still holds that block with exactly these terms. So a button that only exists in a draft or a preview
 * cannot be paid, a raised price retires the old forms at once, and a saved copy of a form stops working after
 * TTL_SECONDS. Offers are signed on public pages only.
 *
 * Two optional terms: `f` (how often: `once`, or `month` for a Stripe subscription) and `h` (0 for a fixed amount; above
 * 0, the visitor types the amount, which must be from `a` up to `h`). An offer with neither signs exactly as before.
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

    /** How long a rendered form keeps working (the expiry is rounded up to the hour, so a page's HTML stays stable). */
    public const TTL_SECONDS = 172_800;

    public const FREQUENCIES = ['once', 'month'];

    /**
     * The terms of an offer from a block's props, or null when they cannot be offered (no amount, a bad currency, ...).
     *
     * @param array<string, mixed> $props
     * @return array{t: int, a: int, c: string, d: string, s: string, f: string, h: int}|null
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
        $frequency = in_array($props['frequency'] ?? 'once', self::FREQUENCIES, true) ? (string) ($props['frequency'] ?? 'once') : 'once';
        $max = 0;
        if (($props['amount_choice'] ?? 'fixed') === 'visitor') {
            $top = $props['max_amount'] ?? null;
            $max = is_int($top) || is_float($top) ? (int) round(((float) $top) * 100) : 0;
            if ($max <= $cents || $max > self::MAX_CENTS) {
                return null;
            }
        }
        return ['t' => $tenantId, 'a' => $cents, 'c' => $currency, 'd' => $description, 's' => $success, 'f' => $frequency, 'h' => $max];
    }

    /**
     * The terms bound to the block that shows them and an expiry, or null for a block without a usable id.
     *
     * @param array{t: int, a: int, c: string, d: string, s: string, f: string, h: int} $terms
     * @return array{t: int, a: int, c: string, d: string, s: string, f: string, h: int, n: string, x: int}|null
     */
    public static function bind(array $terms, string $blockId, int $now): ?array
    {
        if (!self::isBlockId($blockId)) {
            return null;
        }
        return $terms + ['n' => $blockId, 'x' => self::expiry($now)];
    }

    /** The expiry of a form rendered at `$now`: the next full hour plus TTL_SECONDS. */
    public static function expiry(int $now): int
    {
        return (intdiv($now, 3600) + 1) * 3600 + self::TTL_SECONDS;
    }

    public static function isBlockId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
    }

    /**
     * Whether the props a published block has NOW still make exactly the terms of `$offer`.
     *
     * @param array<string, mixed> $offer
     * @param array<string, mixed> $liveProps
     */
    public static function matchesProps(array $offer, array $liveProps, int $tenantId): bool
    {
        $live = self::fromProps($liveProps, $tenantId);
        return $live !== null
            && $live['t'] === ($offer['t'] ?? null) && $live['a'] === ($offer['a'] ?? null)
            && $live['c'] === ($offer['c'] ?? null) && $live['d'] === ($offer['d'] ?? null) && $live['s'] === ($offer['s'] ?? null)
            && $live['f'] === ($offer['f'] ?? 'once') && $live['h'] === ($offer['h'] ?? 0);
    }

    /**
     * Whether `$offer` has the exact shape and limits of an offer (what the endpoint re-checks after the signature).
     *
     * @param array<string, mixed> $offer
     */
    public static function isWellFormed(array $offer): bool
    {
        return isset($offer['t'], $offer['a'], $offer['c'], $offer['d'], $offer['s'], $offer['n'], $offer['x'])
            && is_string($offer['n']) && self::isBlockId($offer['n'])
            && is_int($offer['x']) && $offer['x'] > 0
            && is_int($offer['t']) && $offer['t'] >= 1
            && is_int($offer['a']) && $offer['a'] >= self::MIN_CENTS && $offer['a'] <= self::MAX_CENTS
            && is_string($offer['c']) && in_array($offer['c'], self::CURRENCIES, true)
            && is_string($offer['d']) && mb_strlen($offer['d'], 'UTF-8') <= self::MAX_DESCRIPTION
            && is_string($offer['s']) && ($offer['s'] === '' || self::path($offer['s']) === $offer['s'])
            && in_array($offer['f'] ?? 'once', self::FREQUENCIES, true)
            && is_int($offer['h'] ?? 0) && (($offer['h'] ?? 0) === 0 || (($offer['h'] ?? 0) > $offer['a'] && ($offer['h'] ?? 0) <= self::MAX_CENTS));
    }

    /** The exact text that is signed: every term, in a fixed order, one per line (`f` and `h` only when not the default). */
    public static function canonical(array $offer): string
    {
        $lines = [(string) $offer['t'], (string) $offer['a'], (string) $offer['c'], (string) $offer['d'], (string) $offer['s'], (string) $offer['n'], (string) $offer['x']];
        $frequency = (string) ($offer['f'] ?? 'once');
        $max = (int) ($offer['h'] ?? 0);
        if ($frequency !== 'once' || $max !== 0) {
            array_push($lines, $frequency, (string) $max);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array{t: int, a: int, c: string, d: string, s: string, f: string, h: int, n: string, x: int} $offer
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
     * @return array{t: int, a: int, c: string, d: string, s: string, f: string, h: int, n: string, x: int}|null
     */
    public static function fromPost(array $post, callable $verifier): ?array
    {
        foreach (['t', 'a', 'c', 'd', 's', 'n', 'x', 'g'] as $key) {
            if (!isset($post[$key]) || !is_string($post[$key])) {
                return null;
            }
        }
        foreach (['t', 'a', 'x'] as $key) {
            if (preg_match('/^[1-9][0-9]{0,11}$/', $post[$key]) !== 1) {
                return null;
            }
        }
        // `f` and `h` are optional: a form signed before they existed means a fixed, one-time amount.
        $frequency = $post['f'] ?? 'once';
        $max = $post['h'] ?? '0';
        if (!is_string($frequency) || !is_string($max) || preg_match('/^(?:0|[1-9][0-9]{0,11})$/', $max) !== 1) {
            return null;
        }
        $offer = ['t' => (int) $post['t'], 'a' => (int) $post['a'], 'c' => $post['c'], 'd' => $post['d'], 's' => $post['s'], 'f' => $frequency, 'h' => (int) $max, 'n' => $post['n'], 'x' => (int) $post['x']];
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

    /**
     * The amount a visitor typed, in cents, when it is within the offer's range; null otherwise. Accepts `25`, `25.5`,
     * `25,50` and a leading or trailing currency sign or spaces.
     *
     * @param array{a: int, h: int} $offer
     */
    public static function visitorCents(array $offer, mixed $typed): ?int
    {
        if (!is_string($typed) || strlen($typed) > 20) {
            return null;
        }
        $typed = trim(str_replace([' ', "\u{00A0}", '€', '$', '£'], '', $typed));
        if (preg_match('/^([0-9]{1,9})(?:[.,]([0-9]{1,2}))?$/', $typed, $m) !== 1) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        return $cents >= $offer['a'] && $cents <= $offer['h'] ? $cents : null;
    }

    /** `49.00 USD` for display. */
    public static function display(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2, '.', ',') . ' ' . $currency;
    }
}
