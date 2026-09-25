<?php
/**
 * Slate — notifications live-poll endpoint.
 *
 * Tiny JSON endpoint the admin topbar bell polls on an interval so the
 * unread badge (and the "new notification" sound) update without a page
 * reload. Added 2026-09-06 as part of the client-messaging "live" request —
 * this is intentionally the ONE polling endpoint the whole admin shares
 * (bell badge today; the same {unread} number also drives Coaching's admin
 * chat page — see admin/chat.php's poll script) rather than a bespoke
 * poller per feature.
 */
require_once dirname(__DIR__) . '/config.php';

Auth::require();

header('Content-Type: application/json');

$unread = 0;
if (class_exists('Notifications')) {
    try {
        $unread = Notifications::unreadCount();
    } catch (\Throwable $e) {
        // feed unavailable — report 0 rather than a 500, the badge just won't move this tick
    }
}

echo json_encode(['unread' => (int)$unread]);
