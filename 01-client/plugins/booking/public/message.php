<?php
/**
 * Booking+ — public "human message" step.
 *
 * After a client books, they land here (linked from the auto-response
 * email). They leave a free-text message; we store it against the
 * appointment, stamp the timestamps that drive the inbox + 8-hour
 * nudge, and email the therapist.
 *
 * Access is gated by the booking's `manage_token` (32-char random,
 * created per-appointment by BookingAPI::createAppointment). No account
 * required — the token is the auth.
 *
 * URL: /plugins/booking/public/message.php?t=<manage_token>
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('booking');
ModuleGuard::requirePublic('booking'); // Phase 11: active is not entitled (07 §2, §4)

// Deactivating the plugin must actually turn this endpoint off. Without this
// it stayed live after deactivation — still resolving manage tokens, still
// running ensureSchema(), still writing client_message rows into a plugin the
// operator believes is off. booking/public/pay-intent.php:36 is the model:
// same check, same 503, and in the same position — before the API require, so
// nothing is loaded or written on the way to refusing.
if (!PluginLoader::isCapabilityEnabled('booking', 'client_messaging')) {
    http_response_code(503);
    bookingplus_render_page(__('booking_msg_not_available_title', 'Not available'),
        '<p>' . e(__('booking_msg_not_available_body', 'This page isn\'t available right now. Please reply to your confirmation email instead.')) . '</p>');
    exit;
}

require_once dirname(__DIR__) . '/BookingPlusAPI.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

BookingPlusAPI::ensureSchema();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
if ($token === '' || !preg_match('/^[a-zA-Z0-9]{16,64}$/', $token)) {
    http_response_code(400);
    bookingplus_render_page(__('booking_msg_invalid_link_title', 'Invalid link'),
        '<p>' . e(__('booking_msg_invalid_link_body', 'This link is missing or malformed. Please open the link from the email we sent you after booking, or reply to that email directly.')) . '</p>');
    exit;
}

// Look up the appointment via manage_token. Restrict to future/recent
// appointments — you shouldn't be able to open a message thread against
// a session that's already long past.
// CORE-1: one tenant-scoped lookup for every manage-token surface. This
// call site previously ran its own query with no tenant filter, so a token
// from one tenant resolved against another.
$appt = BookingAPI::findByManageToken($token);
// Recent-or-future only — a thread should not open against a long-past session.
if ($appt !== null && strtotime((string) $appt['starts_at']) < slate_db_time() - 7 * 86400) {
    $appt = null;
}

if (!$appt) {
    http_response_code(404);
    bookingplus_render_page(__('booking_msg_booking_not_found_title', 'Booking not found'),
        '<p>' . e(__('booking_msg_booking_not_found_body', 'We couldn\'t find a booking for that link. If your session has already passed, please just reply to the confirmation email instead.')) . '</p>');
    exit;
}

// Handle submission.
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['submit'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('booking_security_check_failed_retry', 'Security check failed. Please try again.')];
    } else {
        $message = trim((string)($_POST['message'] ?? ''));
        if ($message === '') {
            $flash = ['type' => 'error', 'msg' => __('booking_msg_write_before_sending', 'Please write a message before sending.')];
        } elseif (mb_strlen($message) > 5000) {
            $flash = ['type' => 'error', 'msg' => __('booking_msg_too_long', 'Message is a little long — please shorten it to 5000 characters.')];
        } else {
            try {
                // CORE-1: DB clock — therapist_notified_at is compared
                // against NOW() by the nudge cron (BookingPlus.php:394).
                $now = slate_db_now();
                BookingPlusAPI::saveAppointmentMeta((int)$appt['id'], [
                    'client_message'        => $message,
                    'client_message_at'     => $now,
                    'therapist_notified_at' => $now,
                ]);
                bookingplus_notify_therapist($appt, $message);
                // 2026-09-06: every client message must also reach the shared
                // admin Notifications list, not just email — matches the
                // pattern already used by Coaching's chat (customer/router.php).
                if (class_exists('Notifications')) {
                    Notifications::add(__('booking_notif_new_message', 'New message') . ' · ' . ($appt['customer_name'] ?? __('booking_notif_a_client', 'A client')), [
                        'body' => mb_substr($message, 0, 140),
                        'url'  => SLATE_URL . '/plugins/booking/admin/messages.php',
                        'icon' => 'message-circle',
                    ]);
                }
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('booking_msg_sent_success_fmt', 'Sent — thank you. I\'ll get back to you within %s hours.'),
                    BookingPlusAPI::globalNudgeHours()
                )];
            } catch (\Throwable $e) {
                slate_log('BookingPlus message submit failed: ' . $e->getMessage(), 'error');
                $flash = ['type' => 'error', 'msg' => __('booking_msg_send_failed', 'Something went wrong on our end. Please try again in a moment, or reply to the confirmation email.')];
            }
        }
    }
}

// Any prior message the client already sent — surface it so they know it went through.
$meta          = BookingPlusAPI::getAppointmentMeta((int)$appt['id']);
$existingMsg   = trim((string)($meta['client_message'] ?? ''));
$alreadyReplied = !empty($meta['therapist_replied_at']);
$alreadySent   = $existingMsg !== '' && ($flash === null || $flash['type'] !== 'success');

$whenStr = I18n::localDate('l, j F Y, H:i', strtotime($appt['starts_at']));

// ── Render ──────────────────────────────────────────────────────────
ob_start(); ?>
<div class="bp-card">
    <div class="bp-hero">
        <div class="bp-eyebrow"><?= e(__('booking_your_booking_title', 'Your booking')) ?></div>
        <h1><?= e(bookingplus_message_service_name($appt)) ?></h1>
        <p class="bp-meta"><?= e($whenStr) ?> · <?= e(__('booking_msg_with', 'with')) ?> <?= e($appt['provider_name']) ?></p>
        <p class="bp-meta"><?= e(__('booking_reference_label', 'Reference')) ?> <code><?= e($appt['ref']) ?></code></p>
    </div>

    <?php if ($flash): ?>
        <div class="bp-alert bp-alert-<?= e($flash['type'] === 'success' ? 'success' : 'error') ?>">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if ($alreadyReplied): ?>
        <div class="bp-alert bp-alert-info">
            <?= e(__('booking_msg_already_replied', 'Your message has already been received and replied to. If you have more to add, please reply to that email.')) ?>
        </div>
    <?php elseif ($alreadySent && ($flash['type'] ?? '') !== 'success'): ?>
        <p class="bp-note">
            <?= e(__('booking_msg_already_sent', 'You already sent me a message for this session. If you\'d like to add more, feel free to send another below.')) ?>
        </p>
    <?php endif; ?>

    <?php if (($flash['type'] ?? '') !== 'success'): ?>
        <form method="post" class="bp-form">
            <?= csrf_field() ?>
            <input type="hidden" name="t" value="<?= e($token) ?>">
            <input type="hidden" name="submit" value="1">

            <label for="message" class="bp-label"><?= e(__('booking_msg_label', 'Anything you\'d like me to know before we meet?')) ?></label>
            <p class="bp-hint"><?= e(__('booking_msg_hint', 'Optional — a few sentences about what brings you here, questions, concerns. I read every one.')) ?></p>

            <textarea id="message" name="message" rows="8" required maxlength="5000"
                      placeholder="<?= e(__('booking_msg_placeholder', 'Type here…')) ?>"><?= e((string)($_POST['message'] ?? '')) ?></textarea>

            <div class="bp-actions">
                <button type="submit" class="bp-btn bp-btn-primary"><?= e(__('help_send_message', 'Send message')) ?></button>
            </div>
        </form>
        <?php slate_form_validation_i18n_script(); ?>
    <?php else: ?>
        <div class="bp-actions" style="margin-top:1.5rem;">
            <a href="<?= e(SLATE_URL) ?>" class="bp-btn bp-btn-ghost"><?= e(__('booking_back_to_site', 'Back to the site')) ?></a>
        </div>
    <?php endif; ?>
</div>

<?php
$html = ob_get_clean();
bookingplus_render_page(__('booking_msg_page_title', 'A message about your booking'), $html);


// ── Helpers ─────────────────────────────────────────────────────────

/**
 * Locale-aware service name for the visitor-facing display on this page.
 * Same fix as bookpub_service_name() in router.php: this page must show
 * the service name in the browsing visitor's own locale, not a fixed
 * admin locale. findByManageToken() already selects s.name_fr AS
 * service_name_fr, so no query change is needed here.
 */
function bookingplus_message_service_name(array $appt): string {
    return BookingAPI::serviceName([
        'name'    => $appt['service_name'] ?? '',
        'name_fr' => $appt['service_name_fr'] ?? null,
    ]);
}

function bookingplus_notify_therapist(array $appt, string $message): void {
    $siteName = Database::setting('site_name') ?: 'Kohevo';
    $to = trim((string) (
        Database::setting('booking.notify_admin_email')
        ?: Database::setting('site_admin_email')
        ?: ''
    ));
    if ($to === '') return;

    $subj = sprintf(
        __('booking_email_new_message_subject_fmt', '%s · new message from %s · %s'),
        $siteName,
        ($appt['customer_name'] ?? __('booking_email_a_client', 'a client')),
        $appt['service_name']
    );

    $when = I18n::localDate('l, j F Y, H:i', strtotime($appt['starts_at']));
    $body = \Slate\Services\Notifications\EmailTemplate::compose(
        __('booking_email_new_message_heading', 'New client message'),
        \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('booking_email_new_message_intro', "A client just left you a message about their upcoming booking.")), '0 0 4px')
      . \Slate\Services\Notifications\EmailTemplate::infoCard([
            [__('booking_email_label_client', 'Client'), e((string)$appt['customer_name']) . ' &lt;' . e((string)$appt['customer_email']) . '&gt;'
                . (!empty($appt['customer_phone']) ? ' &middot; ' . e((string)$appt['customer_phone']) : '')],
            [__('service', 'Service'), e((string)$appt['service_name'])],
            [__('booking_email_label_session', 'Session'), e($when)],
            [__('booking_email_label_reference', 'Reference'), '<code>' . e((string)$appt['ref']) . '</code>'],
        ])
      . '<h3 style="margin:0 0 8px;font-size:15px;color:#2d2a26;">' . e(__('booking_email_message_heading', 'Message')) . '</h3>'
      . '<blockquote style="margin:0 0 4px;border-left:3px solid #ece0c4;padding:8px 14px;color:#2d2a26;background:#faf6ec;">' . nl2br(e($message)) . '</blockquote>'
      . \Slate\Services\Notifications\EmailTemplate::button(SLATE_URL . '/plugins/booking/admin/messages.php', __('booking_email_open_messages_link', 'Open Booking+ Messages')),
        $subj
    );

    Mailer::send($to, $subj, $body);
}

function bookingplus_render_page(string $title, string $bodyHtml): void {
    $siteName = Database::setting('site_name') ?: 'Booking';
    $accent   = (string) (Database::setting('brand_accent_color') ?: '#111111');
    $logoRel  = trim((string) Database::setting('brand_logo_path'));
    $logoUrl  = $logoRel !== '' ? SLATE_URL . '/' . ltrim($logoRel, '/') : '';
    ?>
<!doctype html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <style>
        :root { --bp-accent: <?= e($accent) ?>; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e8eff5 100%);
            color: #1a2332;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 20px;
        }
        .bp-topbrand { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; opacity: 0.85; }
        .bp-topbrand img { height: 28px; width: auto; }
        .bp-topbrand span { font-weight: 600; font-size: 15px; color: #334155; }
        .bp-card {
            width: 100%;
            max-width: 640px;
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 20px;
            padding: 32px 32px 28px;
            box-shadow: 0 8px 32px rgba(15,23,42,0.08), 0 2px 8px rgba(15,23,42,0.04);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .bp-hero { margin-bottom: 20px; }
        .bp-eyebrow {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--bp-accent);
            margin-bottom: 6px;
        }
        .bp-hero h1 { font-size: 26px; margin: 0 0 8px; line-height: 1.2; }
        .bp-meta { font-size: 14px; color: #64748b; margin: 2px 0; }
        .bp-meta code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            background: rgba(15,23,42,0.06);
            padding: 1px 6px;
            border-radius: 4px;
        }
        .bp-alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin: 16px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        .bp-alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .bp-alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .bp-alert-info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .bp-note { font-size: 14px; color: #475569; margin: 12px 0; }
        .bp-form { margin-top: 16px; }
        .bp-label {
            display: block;
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 4px;
        }
        .bp-hint { font-size: 13px; color: #64748b; margin: 0 0 10px; }
        textarea {
            width: 100%;
            padding: 12px 14px;
            font: inherit;
            font-size: 14px;
            border: 1px solid rgba(15,23,42,0.15);
            border-radius: 10px;
            background: rgba(255,255,255,0.9);
            resize: vertical;
            min-height: 160px;
            color: inherit;
        }
        textarea:focus {
            outline: none;
            border-color: var(--bp-accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        .bp-actions { margin-top: 16px; display: flex; gap: 10px; }
        .bp-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 10px;
            font: inherit;
            font-weight: 600;
            font-size: 14px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.05s, box-shadow 0.15s;
        }
        .bp-btn-primary {
            background: var(--bp-accent);
            color: #fff;
            box-shadow: 0 2px 8px rgba(37,99,235,0.25);
        }
        .bp-btn-primary:hover { box-shadow: 0 4px 14px rgba(37,99,235,0.35); }
        .bp-btn-primary:active { transform: translateY(1px); }
        .bp-btn-ghost {
            background: rgba(255,255,255,0.7);
            color: #334155;
            border-color: rgba(15,23,42,0.15);
        }
        .bp-btn-ghost:hover { background: rgba(255,255,255,0.95); }
        @media (max-width: 500px) {
            .bp-card { padding: 24px 20px; border-radius: 16px; }
            .bp-hero h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="bp-topbrand">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php endif; ?>
        <span><?= e($siteName) ?></span>
    </div>
    <?= $bodyHtml ?>
</body>
</html>
    <?php
}
