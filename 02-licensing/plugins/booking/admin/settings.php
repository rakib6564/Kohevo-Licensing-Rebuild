<?php
/**
 * Booking — settings (reminders, follow-ups, SMS/WhatsApp gateway).
 * Values are stored in the core settings table under the "booking." prefix,
 * matching Plugin::setting()/BookingAPI reads.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_settings');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_settings', 'Booking settings');
$currentNav = 'booking-settings';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        // Normalise reminder leads to a clean CSV of positive minutes.
        $leads = [];
        foreach (explode(',', (string)($_POST['reminder_leads'] ?? '')) as $p) {
            $m = (int) trim($p);
            if ($m > 0) $leads[$m] = true;
        }
        $leadsCsv = implode(',', array_keys($leads))
                 ?: (string) Hook::applyFilters('booking_default_reminder_leads', '1440,60');

        Database::setSetting('booking.reminder_leads',       $leadsCsv);

        // Confirmation workflow: 'auto' (default, current behaviour) or
        // 'manual' (a booking that doesn't need payment holds as
        // 'awaiting_approval' until staff act on it — see BookingAPI::
        // confirmationMode()/approveAppointment()/declineAppointment()).
        Database::setSetting('booking.confirmation_mode',
            ($_POST['confirmation_mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto');

        // Booking-widget feature toggles (default on). Each controls
        // whether the matching section renders on the public confirm step.
        Database::setSetting('booking.show_coupon',     !empty($_POST['show_coupon'])    ? '1' : '0');
        Database::setSetting('booking.show_gift_card',  !empty($_POST['show_gift_card']) ? '1' : '0');
        Database::setSetting('booking.show_repeat',     !empty($_POST['show_repeat'])    ? '1' : '0');
        Database::setSetting('booking.show_capacity',   !empty($_POST['show_capacity'])  ? '1' : '0');

        // Skip the provider-selection step when a service has exactly one
        // assigned provider — there's no real choice to make. Off by
        // default since it changes existing behaviour (see
        // multislot_enabled below for the same off-by-default idiom).
        Database::setSetting('booking.auto_skip_single_provider', !empty($_POST['auto_skip_single_provider']) ? '1' : '0');

        // Multi-slot booking — lets a customer add several distinct
        // date/time picks (any dates, not necessarily weekly-spaced) to one
        // submission instead of one appointment per visit. Off by default —
        // an admin opts in. "Max" bounds how many times one submission can
        // hold, enforced again server-side on POST (never trust the widget).
        Database::setSetting('booking.multislot_enabled', !empty($_POST['multislot_enabled']) ? '1' : '0');
        Database::setSetting('booking.multislot_max',
            (string) max(2, min(20, (int)($_POST['multislot_max'] ?? 4))));

        // Websites allowed to show the widget in an <iframe> (also the only sites a ?return= may point at).
        Database::setSetting('embed_allowed_origins', implode("\n", slate_normalize_embed_origins((string)($_POST['embed_allowed_origins'] ?? ''))));

        // Customer self-service — controls the /book/manage page only.
        // Admin-initiated cancel/reschedule is never gated by this (see
        // BookingAPI::canSelfCancel()/canSelfReschedule()).
        Database::setSetting('booking.self_cancel_enabled',     !empty($_POST['self_cancel_enabled'])     ? '1' : '0');
        Database::setSetting('booking.self_reschedule_enabled', !empty($_POST['self_reschedule_enabled']) ? '1' : '0');
        Database::setSetting('booking.cancel_min_notice_hours',     (string) max(0, (int)($_POST['cancel_min_notice_hours'] ?? 0)));
        Database::setSetting('booking.reschedule_min_notice_hours', (string) max(0, (int)($_POST['reschedule_min_notice_hours'] ?? 0)));
        Database::setSetting('booking.max_reschedules',              (string) max(0, (int)($_POST['max_reschedules'] ?? 0)));

        // Google Calendar 2-way sync — master toggle + OAuth app credentials.
        // Secret is write-only (like the Twilio token below) and stored
        // encrypted at rest via slate_encrypt_secret().
        Database::setSetting('booking.google_enabled',   !empty($_POST['google_enabled']) ? '1' : '0');
        Database::setSetting('booking.google_client_id', trim((string)($_POST['google_client_id'] ?? '')));
        $gClientSecret = trim((string)($_POST['google_client_secret'] ?? ''));
        if ($gClientSecret !== '') {
            Database::setSetting('booking.google_client_secret', slate_encrypt_secret($gClientSecret));
        }

        Database::setSetting('booking.followup_enabled',     !empty($_POST['followup_enabled']) ? '1' : '0');
        Database::setSetting('booking.followup_delay_hours', (string) max(1, (int)($_POST['followup_delay_hours'] ?? 24)));
        Database::setSetting('booking.loyalty_points_per_booking', (string) max(0, (int)($_POST['loyalty_points_per_booking'] ?? 0)));
        Database::setSetting('booking.sms_enabled',          !empty($_POST['sms_enabled']) ? '1' : '0');
        Database::setSetting('booking.whatsapp_enabled',     !empty($_POST['whatsapp_enabled']) ? '1' : '0');
        Database::setSetting('booking.twilio_sid',           trim((string)($_POST['twilio_sid'] ?? '')));
        Database::setSetting('booking.twilio_sms_from',      trim((string)($_POST['twilio_sms_from'] ?? '')));
        Database::setSetting('booking.twilio_whatsapp_from', trim((string)($_POST['twilio_whatsapp_from'] ?? '')));
        // Token is write-only (like the Google client secret above) and
        // stored encrypted at rest via slate_encrypt_secret(); only
        // overwrite when a new value is supplied.
        $token = trim((string)($_POST['twilio_token'] ?? ''));
        if ($token !== '') Database::setSetting('booking.twilio_token', slate_encrypt_secret($token));

        // The seam. Booking owns this screen, this form and this POST; a
        // plugin that needs a booking setting contributes a card via
        // `booking_settings_cards` and saves it here, instead of shipping a
        // second settings screen. Listeners handle their own permission check
        // — Booking cannot know what theirs is.
        Hook::doAction('booking_settings_save', $_POST);

        AuditLog::record('booking.settings_updated', 'booking');
        $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];
    }
}

$g = fn(string $k, $d = '') => (string)(Database::setting('booking.' . $k) ?? $d);
$hasToken = $g('twilio_token') !== '';
$hasGoogleSecret = $g('google_client_secret') !== '';
$googleRedirectUri = rtrim(SLATE_URL, '/') . '/plugins/booking/admin/gcal-callback.php';
$googleWebhookUrl  = rtrim(SLATE_URL, '/') . '/plugins/booking/public/gcal-webhook.php';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('settings', 'Settings')],
]); ?>

<div class="page-header"><div><h1><?= __('booking_settings', 'Booking settings') ?></h1></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_widget_title', 'Booking widget') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_widget_desc', "Show or hide optional sections on the public booking form's confirm step.") ?></p>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_coupon" value="1" <?= $g('show_coupon', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_show_coupon_field', 'Show <strong>coupon code</strong> field') ?></span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_gift_card" value="1" <?= $g('show_gift_card', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_show_gift_card_field', 'Show <strong>gift card</strong> field') ?></span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_repeat" value="1" <?= $g('show_repeat', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_show_repeat_field', 'Show <strong>Repeat</strong> (weekly recurring) options') ?></span>
            </label>
            <div class="field-hint"><?= __('booking_settings_repeat_hint', 'When off, every booking is one-time.') ?></div>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_capacity" value="1" <?= $g('show_capacity', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_show_capacity_field', 'Show <strong>spots left</strong> on each time slot') ?></span>
            </label>
            <div class="field-hint"><?= __('booking_settings_capacity_hint', 'Only shown for services with a group capacity above 1 — e.g. "3 spots left".') ?></div>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="auto_skip_single_provider" value="1" <?= $g('auto_skip_single_provider', '0') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_auto_skip_single_provider_field', 'Skip the <strong>provider selection</strong> step when a service has only one provider') ?></span>
            </label>
            <div class="field-hint"><?= __('booking_settings_auto_skip_single_provider_hint', 'When on, and a service has exactly one assigned provider, the customer goes straight from service selection to date/time.') ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_multislot_title', 'Multi-slot booking') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_multislot_desc', 'Let a customer pick several different times — any dates, not just the same day each week — and submit them together as one booking, instead of repeating the whole flow per appointment.') ?></p>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="multislot_enabled" value="1" <?= $g('multislot_enabled', '0') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_multislot_allow', 'Allow customers to <strong>select multiple times</strong> in one booking') ?></span>
            </label>
            <div class="field-hint"><?= __('booking_settings_multislot_hint', 'When on, tapping a time on the public widget adds it to a running list instead of continuing right away — the customer reviews their picks and confirms them all at once.') ?></div>
        </div>
        <div class="field" style="max-width:220px;">
            <label class="field-label" for="multislot_max"><?= __('booking_settings_multislot_max_label', 'Maximum times per booking') ?></label>
            <input type="number" id="multislot_max" name="multislot_max" min="2" max="20"
                   value="<?= (int)$g('multislot_max', '4') ?>">
            <div class="field-hint"><?= __('booking_settings_multislot_max_hint', 'Enforced on submission regardless of what the widget shows.') ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_embed_title', 'Embedding on other websites') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_embed_desc', 'Websites allowed to show the booking widget in an iframe. Signing in, the confirmation step and payment always open on this site for security, then send the visitor back to their page.') ?></p>
        <div class="field">
            <label class="field-label" for="embed_allowed_origins"><?= __('booking_settings_embed_label', 'Allowed websites (one per line)') ?></label>
            <textarea id="embed_allowed_origins" name="embed_allowed_origins" rows="3" placeholder="https://www.example.com"><?= e(implode("\n", slate_embed_allowed_origins())) ?></textarea>
            <div class="field-hint"><?= __('booking_settings_embed_hint', 'Only the site address, without a page path. Anything not listed here cannot frame your pages.') ?></div>
        </div>
        <div class="field">
            <label class="field-label"><?= __('booking_settings_embed_code_label', 'Embed code') ?></label>
            <?= BookingAPI::embedSnippetBlock('book-embed-snippet-settings') ?>
            <div class="field-hint"><?= __('booking_settings_embed_code_hint', 'Paste both lines where the widget should appear. The script resizes the frame to fit each step. Add &chrome=0 to the address for a flat look without the card border.') ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_confirmations_title', 'Booking confirmations') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_confirmations_desc', "A booking that needs online payment always confirms once payment succeeds, same as today. This setting controls the other case — a free or on-site booking, which currently confirms itself the instant it's submitted.") ?></p>
        <div class="field">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                <input type="radio" name="confirmation_mode" value="auto" <?= $g('confirmation_mode', 'auto') !== 'manual' ? 'checked' : '' ?> style="margin-top:3px;">
                <span><?= __('booking_settings_confirm_auto_label', "<strong>Auto-confirm</strong> — a booking that doesn't need payment is confirmed immediately (current behaviour).") ?></span>
            </label>
        </div>
        <div class="field">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                <input type="radio" name="confirmation_mode" value="manual" <?= $g('confirmation_mode', 'auto') === 'manual' ? 'checked' : '' ?> style="margin-top:3px;">
                <span><?= __('booking_settings_confirm_manual_label', '<strong>Require manual approval</strong> — it\'s held as "Awaiting approval" and the slot stays reserved until a staff member approves or declines it from the appointment page. The customer is emailed either way.') ?></span>
            </label>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_self_service_title', 'Customer self-service') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_self_service_desc', 'Controls what a customer can do themselves from their booking-confirmation "manage" link, without contacting you. Staff changes made from the admin are never affected by these settings.') ?></p>

        <div class="field-row field-row-2">
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="self_cancel_enabled" value="1" <?= $g('self_cancel_enabled', '1') !== '0' ? 'checked' : '' ?>>
                    <span><?= __('booking_settings_self_cancel_label', 'Allow customers to cancel online') ?></span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="cancel_min_notice_hours"><?= __('booking_settings_cancel_notice_label', 'Minimum notice to cancel (hours)') ?></label>
                <input type="number" id="cancel_min_notice_hours" name="cancel_min_notice_hours" min="0" step="1" value="<?= e($g('cancel_min_notice_hours', '0')) ?>">
                <div class="field-hint"><?= __('booking_settings_cancel_notice_hint', '0 = no minimum. e.g. 24 = 1 day before, 48 = 2 days before.') ?></div>
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="self_reschedule_enabled" value="1" <?= $g('self_reschedule_enabled', '1') !== '0' ? 'checked' : '' ?>>
                    <span><?= __('booking_settings_self_reschedule_label', 'Allow customers to reschedule online') ?></span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="reschedule_min_notice_hours"><?= __('booking_settings_reschedule_notice_label', 'Minimum notice to reschedule (hours)') ?></label>
                <input type="number" id="reschedule_min_notice_hours" name="reschedule_min_notice_hours" min="0" step="1" value="<?= e($g('reschedule_min_notice_hours', '0')) ?>">
                <div class="field-hint"><?= __('booking_settings_reschedule_notice_hint', '0 = no minimum.') ?></div>
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="max_reschedules"><?= __('booking_settings_max_reschedules_label', 'Maximum self-reschedules per booking') ?></label>
            <input type="number" id="max_reschedules" name="max_reschedules" min="0" step="1" value="<?= e($g('max_reschedules', '0')) ?>" style="max-width:160px;">
            <div class="field-hint"><?= __('booking_settings_max_reschedules_hint', '0 = unlimited. Once a booking hits this count, the customer is told to contact you directly to move it again.') ?></div>
        </div>

        <div class="field-hint" style="margin-top:4px;"><?= __('booking_settings_no_change_hint', "A booking that's already been cancelled, completed, marked no-show, or has already started can never be changed online, regardless of these settings.") ?></div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_reminders_title', 'Reminders &amp; follow-ups') ?></h2></div>
        <div class="field">
            <label class="field-label" for="reminder_leads"><?= __('booking_settings_reminder_leads_label', 'Reminder lead times (minutes, comma-separated)') ?></label>
            <input type="text" id="reminder_leads" name="reminder_leads" value="<?= e($g('reminder_leads', '1440,60')) ?>" placeholder="1440,60">
            <div class="field-hint"><?= __('booking_settings_reminder_leads_hint', 'e.g. <code>1440,60</code> = 24 hours and 1 hour before. Sent by email (and SMS/WhatsApp if enabled).') ?></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="followup_enabled" value="1" <?= $g('followup_enabled') === '1' ? 'checked' : '' ?>>
                    <span><?= __('booking_settings_followup_label', 'Send a follow-up / feedback email after the appointment') ?></span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="followup_delay_hours"><?= __('booking_settings_followup_delay_label', 'Follow-up delay (hours after end)') ?></label>
                <input type="number" id="followup_delay_hours" name="followup_delay_hours" min="1" step="1" value="<?= e($g('followup_delay_hours', '24')) ?>">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="loyalty_points_per_booking"><?= __('booking_settings_loyalty_label', 'Loyalty points per completed appointment') ?></label>
            <input type="number" id="loyalty_points_per_booking" name="loyalty_points_per_booking" min="0" step="1" value="<?= e($g('loyalty_points_per_booking', '0')) ?>">
            <div class="field-hint"><?= __('booking_settings_loyalty_hint', 'Awarded automatically when an appointment is marked completed. 0 disables loyalty.') ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_sms_whatsapp_title', 'SMS &amp; WhatsApp (Twilio)') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_twilio_desc', 'Customers with a phone number receive text/WhatsApp confirmations and reminders when enabled.') ?></p>
        <div class="field-row field-row-2">
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="sms_enabled" value="1" <?= $g('sms_enabled') === '1' ? 'checked' : '' ?>>
                    <span><?= __('booking_settings_sms_enable_label', 'Enable SMS reminders') ?></span>
                </label>
            </div>
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="whatsapp_enabled" value="1" <?= $g('whatsapp_enabled') === '1' ? 'checked' : '' ?>>
                    <span><?= __('booking_settings_whatsapp_enable_label', 'Enable WhatsApp messages') ?></span>
                </label>
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="twilio_sid"><?= __('booking_settings_twilio_sid_label', 'Twilio Account SID') ?></label>
                <input type="text" id="twilio_sid" name="twilio_sid" value="<?= e($g('twilio_sid')) ?>" autocomplete="off">
            </div>
            <div class="field">
                <label class="field-label" for="twilio_token"><?= __('booking_settings_twilio_token_label', 'Auth Token') ?> <?php if ($hasToken): ?><span class="text-muted text-xs"><?= __('booking_settings_secret_set_hint', '(set — leave blank to keep)') ?></span><?php endif; ?></label>
                <input type="password" id="twilio_token" name="twilio_token" value="" autocomplete="new-password" placeholder="<?= $hasToken ? '••••••••' : '' ?>">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="twilio_sms_from"><?= __('booking_settings_sms_sender_label', 'SMS sender (E.164)') ?></label>
                <input type="text" id="twilio_sms_from" name="twilio_sms_from" value="<?= e($g('twilio_sms_from')) ?>" placeholder="+15555550123">
            </div>
            <div class="field">
                <label class="field-label" for="twilio_whatsapp_from"><?= __('booking_settings_whatsapp_sender_label', 'WhatsApp sender') ?></label>
                <input type="text" id="twilio_whatsapp_from" name="twilio_whatsapp_from" value="<?= e($g('twilio_whatsapp_from')) ?>" placeholder="+15555550123">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('booking_settings_gcal_title', 'Google Calendar (2-way sync)') ?></h2></div>
        <p class="text-sm text-muted"><?= __('booking_settings_gcal_desc', 'Each provider connects their own Google Calendar from their profile (Providers → edit → Google Calendar). Once connected, bookings push to their calendar automatically, and events they create or change directly in Google — including time blocked off for other things — flow back into Kohevo so double-booking is avoided.') ?></p>

        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="google_enabled" value="1" <?= $g('google_enabled') === '1' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('booking_settings_gcal_enable_label', 'Enable Google Calendar sync') ?></span>
            </label>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="google_client_id"><?= __('booking_settings_gcal_client_id_label', 'OAuth Client ID') ?></label>
                <input type="text" id="google_client_id" name="google_client_id" value="<?= e($g('google_client_id')) ?>" autocomplete="off" placeholder="xxxxx.apps.googleusercontent.com">
            </div>
            <div class="field">
                <label class="field-label" for="google_client_secret"><?= __('booking_settings_gcal_client_secret_label', 'OAuth Client Secret') ?> <?php if ($hasGoogleSecret): ?><span class="text-muted text-xs"><?= __('booking_settings_secret_set_hint', '(set — leave blank to keep)') ?></span><?php endif; ?></label>
                <input type="password" id="google_client_secret" name="google_client_secret" value="" autocomplete="new-password" placeholder="<?= $hasGoogleSecret ? '••••••••' : '' ?>">
            </div>
        </div>

        <div class="field">
            <label class="field-label"><?= __('booking_settings_gcal_redirect_label', 'Authorized redirect URI') ?></label>
            <input type="text" readonly value="<?= e($googleRedirectUri) ?>" onclick="this.select()" style="font-family:monospace;font-size:12.5px;">
            <div class="field-hint"><?= __('booking_settings_gcal_redirect_hint', 'Add this exact URL under "Authorized redirect URIs" for your OAuth client in the Google Cloud Console.') ?></div>
        </div>
        <div class="field">
            <label class="field-label"><?= __('booking_settings_gcal_webhook_label', 'Push-notification webhook URL') ?></label>
            <input type="text" readonly value="<?= e($googleWebhookUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:12.5px;">
            <div class="field-hint"><?= __('booking_settings_gcal_webhook_hint', 'Registered automatically per provider once connected — nothing to configure here. Requires a public HTTPS domain; on a local/HTTP install, sync still runs on the regular cron schedule instead of instantly.') ?></div>
        </div>

        <div class="field-hint">
            <?= __('booking_settings_gcal_setup_hint', 'Setup: create a project in the <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>, enable the <strong>Google Calendar API</strong>, create an OAuth 2.0 Client ID (type: Web application) with the redirect URI above, and paste its Client ID/Secret here. Then open each provider\'s profile to connect their calendar.') ?>
        </div>
    </div>

    <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary"><?= __('booking_settings_save_button', 'Save settings') ?></button>
    </div>
<?= Hook::applyFilters('booking_settings_cards', '') ?>

</form>

<?php
// Manual sync trigger + status — deliberately its own small form outside the
// settings form above: triggering a sync is a separate, immediate action,
// not part of "Save settings".
$gConnectedCount = (int) Database::value(
    "SELECT COUNT(*) FROM booking_providers WHERE tenant_id = ? AND google_refresh_token IS NOT NULL",
    [current_tenant_id()]
);
$gLastSync = (string) Database::setting('booking.google_last_cron_at');
?>
<?php if ($g('google_enabled') === '1'): ?>
<div class="card">
    <div class="card-header"><h2><?= __('booking_settings_sync_status_title', 'Sync status') ?></h2></div>
    <p class="text-sm text-muted">
        <?= $gConnectedCount === 1
            ? sprintf(__('booking_settings_providers_connected_one', '%d provider connected.'), $gConnectedCount)
            : sprintf(__('booking_settings_providers_connected_other', '%d providers connected.'), $gConnectedCount) ?>
        <?= $gLastSync !== ''
            ? sprintf(__('booking_settings_last_sync_fmt', 'Last automatic sync: %s UTC.'), e(I18n::localDate('M j, Y H:i', strtotime($gLastSync))))
            : __('booking_settings_no_sync_yet', "No automatic sync has run yet — it runs on the site's regular cron schedule.") ?>
    </p>
    <form method="post" action="<?= e(SLATE_URL) ?>/plugins/booking/admin/gcal-sync-now.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm"><?= __('booking_settings_sync_now_button', 'Sync now') ?></button>
    </form>
</div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
