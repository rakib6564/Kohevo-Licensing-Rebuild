<?php
/**
 * Slate — customer logout.
 */
require_once dirname(__DIR__) . '/config.php';

// CSRF-guard the logout GET so a cross-site request can't force it.
if (!csrf_verify($_GET['csrf'] ?? '')) {
    header('Location: ' . SLATE_URL . '/customer/');
    exit;
}

Auth::logoutCustomer();

header('Location: ' . SLATE_URL . '/customer/login.php');
exit;
