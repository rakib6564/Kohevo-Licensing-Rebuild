<?php
/**
 * Slate — Customer Portal central router.
 *
 * Dispatches all `/member` and `/member/*` customer URLs:
 *   /member                       → Portal Home Dashboard
 *   /member/book                  → Shared Booking Flow
 *   /member/activity              → Combined Activity Timeline
 *   /member/account               → Profile & Account Settings
 *   /member/membership/*          → Membership Feature Area
 *   /member/coaching/*            → Coaching Feature Area
 *   /member/clientdesk/*          → ClientDesk Projects
 *   /member?view=...              → Legacy Membership Route Adapter
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__) . '/config.php';
}

require_once SLATE_ROOT . '/includes/portal_shell.php';
require_once SLATE_ROOT . '/includes/portal_ui.php';

use Slate\Services\Auth\Auth;
use Slate\Services\Portal\CustomerPortal;

Auth::requireCustomer();

$routePath = trim((string) ($_GET['_route_path'] ?? ''), '/');

// Parse sub-path segments
$parts = $routePath !== '' ? explode('/', $routePath) : [];
$section = $parts[0] ?? '';
$action  = $parts[1] ?? '';

// ── Legacy Route Adapters ────────────────────────────────────────────────
// If /member?view=... is requested without a subpath, adapt to the appropriate feature
if ($section === '' && isset($_GET['view'])) {
    $view = (string) $_GET['view'];
    $membershipViews = ['plans', 'card', 'schedule', 'wallet', 'onboarding', 'profile', 'return'];
    if (in_array($view, $membershipViews, true) || $view === 'home') {
        $section = 'membership';
        $action  = $view;
    }
}

// ── Feature Dispatcher ───────────────────────────────────────────────────

// 1. Membership Feature (/member/membership/*)
if ($section === 'membership') {
    if ($action !== '') {
        // Map /member/membership/overview to ?view=home
        $_GET['view'] = ($action === 'overview') ? 'home' : $action;
    }
    $memRouter = SLATE_ROOT . '/plugins/membership/public/router.php';
    if (is_file($memRouter)) {
        require $memRouter;
        exit;
    }
}

// 2. Coaching Feature (/member/coaching/*)
if ($section === 'coaching') {
    if ($action !== '') {
        $_GET['view'] = ($action === 'overview') ? 'home' : $action;
    }
    $coachRouter = SLATE_ROOT . '/plugins/coaching/customer/router.php';
    if (is_file($coachRouter)) {
        require $coachRouter;
        exit;
    }
}

// 3. Clientdesk Feature (/member/clientdesk/*)
if ($section === 'clientdesk') {
    $cdRouter = SLATE_ROOT . '/plugins/clientdesk/customer/dashboard.php';
    if (is_file($cdRouter)) {
        require $cdRouter;
        exit;
    }
}

// 4. Shared Booking Flow (/member/book)
if ($section === 'book') {
    $bookPage = SLATE_ROOT . '/customer/book.php';
    if (is_file($bookPage)) {
        require $bookPage;
        exit;
    }
    // Fallback if standalone customer/book.php is not yet loaded: redirect to public /book
    header('Location: ' . SLATE_URL . '/book');
    exit;
}

// 5. Combined Activity Timeline (/member/activity)
if ($section === 'activity') {
    require SLATE_ROOT . '/customer/activity.php';
    exit;
}

// 6. Central Account & Profile (/member/account)
if ($section === 'account') {
    require SLATE_ROOT . '/customer/profile.php';
    exit;
}

// 7. Default: Portal Home Dashboard (/member)
require SLATE_ROOT . '/customer/index.php';
