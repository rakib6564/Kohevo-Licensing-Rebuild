<?php
/**
 * Slate — admin logout.
 */
require_once dirname(__DIR__) . '/config.php';

// Require a valid CSRF token so a cross-site GET (e.g. an <img> tag) can't
// force a logout. Legit logout links carry the per-session token; a forged
// request can't know it, so we bounce back without logging out.
if (!csrf_verify($_GET['csrf'] ?? '')) {
    header('Location: ' . SLATE_URL . '/admin/');
    exit;
}

if (Auth::check()) {
    AuditLog::record('logout', Auth::user()['email']);
    Auth::logout();
}

header('Location: ' . SLATE_URL . '/admin/login.php');
exit;
