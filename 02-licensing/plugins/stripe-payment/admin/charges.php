<?php
/**
 * Stripe Payment — Charges admin page.
 *
 * The cross-gateway Transactions page now lives in core
 * (admin/payments.php). This entry keeps the Stripe nav item and its own
 * stripe.manage_charges permission working: the check runs here first, then
 * the shared page renders (it accepts either permission).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StripePaymentAPI.php';

Auth::require();
Auth::requirePerm('stripe.manage_charges');

StripePaymentAPI::ensureChargesSchema();

$paymentsPageUrl = plugin_url('stripe-payment', 'admin/charges.php');
$currentNav      = 'payments-transactions';
require SLATE_ROOT . '/admin/payments.php';
