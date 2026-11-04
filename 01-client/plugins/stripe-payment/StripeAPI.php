<?php
/**
 * StripeAPI — kept as a thin shim over StripePaymentAPI's key and mode
 * accessors, for code written against the v1.x plugin. Its Shop cart
 * helpers went with the old Shop checkout in 1.3.0. New code takes
 * payments through Payments, or StripePaymentAPI for Stripe-only features.
 */

require_once __DIR__ . '/StripePaymentAPI.php';

class StripeAPI {

    public static function secretKey(): string      { return StripePaymentAPI::secretKey(); }
    public static function publishableKey(): string { return StripePaymentAPI::publishableKey(); }
    public static function webhookSecret(): string  { return StripePaymentAPI::webhookSecret(); }
    public static function mode(): string           { return StripePaymentAPI::mode(); }
    public static function isConfigured(): bool     { return StripePaymentAPI::isConfigured(); }
}
