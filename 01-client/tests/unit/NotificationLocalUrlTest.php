<?php
/**
 * Unit tests for a notification's link after the site moved: Notifications::localUrl().
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}
if (!defined('SLATE_URL')) {
    define('SLATE_URL', 'https://example.test/new');
}

use Slate\Services\Notifications\Notifications;

unit('notifications: a link saved under an old folder or domain opens on this site', function (): void {
    assert_eq(SLATE_URL . '/plugins/forms/admin/submission.php?id=5', Notifications::localUrl('https://old.test/solaya/plugins/forms/admin/submission.php?id=5'));
    assert_eq(SLATE_URL . '/admin/orders.php', Notifications::localUrl('https://old.test/admin/orders.php'));
});

unit('notifications: a link on this site, an empty one and any other address stay as they are', function (): void {
    $own = SLATE_URL . '/admin/notifications.php';
    assert_eq($own, Notifications::localUrl($own));
    assert_eq('', Notifications::localUrl(''));
    assert_eq('https://other.test/page/', Notifications::localUrl('https://other.test/page/'));
    assert_eq('/plugins/x', Notifications::localUrl('/plugins/x'));
});
