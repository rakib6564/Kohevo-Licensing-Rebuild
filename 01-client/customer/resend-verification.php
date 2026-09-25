<?php
/**
 * Slate — resend verification email.
 *
 * POST-only. Requires a logged-in customer. Sends a fresh verification
 * token and redirects back to the portal with a flash.
 */
require_once dirname(__DIR__) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

Auth::requireCustomer();

$id = Auth::customerId();
if ($id) {
    Auth::sendCustomerVerification((int)$id);
}

// Set a one-shot flash in the session for the next page render.
$_SESSION['slate_customer_flash'] = [
    'type' => 'success',
    'msg'  => __('verification_resent', 'Verification email sent. Check your inbox.'),
];

header('Location: ' . SLATE_URL . '/customer/');
exit;
