<?php
/**
 * Kohevo Studio (studio-builder) — which links a `payment.link` block may point to.
 *
 * Only Stripe's own hosted pages over https: Payment Links and Buy Buttons (buy.stripe.com), Checkout (checkout.stripe.com),
 * donation links (donate.stripe.com) and the customer portal (billing.stripe.com). The block stores the URL as text; both the
 * schema (on save) and the renderer (on output) use the same test, so nothing else can ever be rendered as a payment button.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Payment;

final class PaymentLinkUrl
{
    /** Empty (not set yet) or a Stripe-hosted https URL made of URL-safe characters. */
    public const PATTERN = '!^(?:https://(?:buy|checkout|donate|billing)\.stripe\.com/[A-Za-z0-9_\-/.?=&%#+:~]{1,500})?$!';

    public static function isAllowed(string $url): bool
    {
        return $url !== '' && preg_match(self::PATTERN, $url) === 1;
    }
}
