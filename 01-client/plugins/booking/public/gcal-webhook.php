<?php
/**
 * Booking — Google Calendar push-notification receiver ("watch" channel).
 *
 * Google POSTs here with no body, only headers, whenever a watched
 * calendar changes. This is a best-effort real-time nudge only — the
 * `frequent_cron` sweep (GoogleCalendarSync::runCron()) is the reliable
 * baseline that guarantees every install eventually converges even if a
 * notification is lost, arrives late, or this endpoint is unreachable.
 *
 * Always answer 200 quickly: a non-2xx response makes Google retry with
 * backoff and eventually stop delivering to this channel altogether, and
 * there is nothing the caller can usefully do with an error here — an
 * unrecognized/forged notification is simply ignored.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

http_response_code(200);
header('Content-Type: text/plain');

if (!PluginLoader::isActive('booking')) {
    echo 'ok';
    exit;
}
require_once dirname(__DIR__) . '/BookingAPI.php';

$channelId   = (string)($_SERVER['HTTP_X_GOOG_CHANNEL_ID']   ?? '');
$resourceId  = (string)($_SERVER['HTTP_X_GOOG_RESOURCE_ID']  ?? '');
$channelTok  = (string)($_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN']?? '');
$resourceState = (string)($_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? '');

// 'sync' is Google's initial handshake message when the channel is first
// created — nothing changed yet, just acknowledge it.
if ($channelId !== '' && $resourceId !== '' && $resourceState !== 'sync') {
    try {
        GoogleCalendarSync::handleWebhook($channelId, $resourceId, $channelTok);
    } catch (\Throwable $e) {
        slate_log('Booking gcal-webhook: ' . $e->getMessage(), 'warning');
    }
}

echo 'ok';
