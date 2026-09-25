<?php
/**
 * Booking — per-service rules editor (glass UI).
 *
 * Uses the shared slate_edit_* record-editor kit for a consistent look
 * with core Booking / Membership editors.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('booking.manage_services');

// See admin/service-rules.php's identical guard: the capability toggle must
// actually turn this editor off, not just hide its nav link.
if (!PluginLoader::isCapabilityEnabled('booking', 'service_rules')) {
    http_response_code(503);
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('booking_msg_not_available_title', 'Not available') . '</div>'
       . '<p class="text-sm">' . __('booking_service_rule_disabled_body', 'Service rules are currently disabled. Enable them in Booking &rarr; Settings.') . '</p></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

BookingPlusAPI::ensureSchema();

$tid       = current_tenant_id();
$serviceId = (int) ($_GET['id'] ?? 0);
$service   = $serviceId > 0
    ? Database::row("SELECT * FROM booking_services WHERE id = ? AND tenant_id = ?", [$serviceId, $tid])
    : null;

if (!$service) {
    header('Location: ' . plugin_url('booking', 'admin/service-rules.php'));
    exit;
}

$otherServices = Database::rows(
    "SELECT id, name FROM booking_services
      WHERE tenant_id = ? AND id != ? AND is_active = 1
      ORDER BY sort_order, name",
    [$tid, $serviceId]
);

function bplus_nullable(string $s): ?string {
    $t = trim($s);
    return $t === '' ? null : $t;
}

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('booking_security_check_failed', 'Security check failed.')];
    } else {
        try {
            $fields = [
                'min_advance_days'        => max(0, (int)($_POST['min_advance_days'] ?? 0)),
                'prereq_service_id'       => ((int)($_POST['prereq_service_id'] ?? 0)) ?: null,
                'prereq_message'          => bplus_nullable($_POST['prereq_message'] ?? ''),
                'hsr_redirect_service_id' => ((int)($_POST['hsr_redirect_service_id'] ?? 0)) ?: null,
                'prep_page_url'           => bplus_nullable($_POST['prep_page_url'] ?? ''),
                'whatsapp_url'            => bplus_nullable($_POST['whatsapp_url'] ?? ''),
                'auto_response_subject'   => bplus_nullable($_POST['auto_response_subject'] ?? ''),
                'auto_response_body'      => bplus_nullable($_POST['auto_response_body'] ?? ''),
                'reminder_8day_body'      => bplus_nullable($_POST['reminder_8day_body'] ?? ''),
                'reminder_1day_body'      => bplus_nullable($_POST['reminder_1day_body'] ?? ''),
                'reminder_10min_body'     => bplus_nullable($_POST['reminder_10min_body'] ?? ''),
                'zoom_mode'               => (string)($_POST['zoom_mode'] ?? 'fallback_message'),
                'zoom_join_url'           => bplus_nullable($_POST['zoom_join_url'] ?? ''),
            ];
            BookingPlusAPI::saveServiceConfig($serviceId, $fields);
            $flash = ['type' => 'success', 'msg' => __('booking_service_rule_saved', 'Saved.')];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

$cfg = BookingPlusAPI::getServiceConfig($serviceId);

$pageTitle  = 'Booking+ · ' . $service['name'];
$currentNav = 'bookingplus-services';

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'),   'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_service_rules', 'Service rules'), 'href' => plugin_url('booking', 'admin/service-rules.php')],
    ['label' => $service['name']],
]);

$statActive = (int)$cfg['min_advance_days'] > 0
    ? (int)$cfg['min_advance_days'] . '<small>' . __('booking_service_rule_day_min_suffix', 'day min') . '</small>'
    : '—';
$statPrereq = (int)$cfg['prereq_service_id'] > 0 ? __('booking_service_rule_stat_set', 'Set') : '—';
$statAuto   = trim((string)$cfg['auto_response_body']) !== '' ? __('booking_service_rule_stat_on', 'On') : '—';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-4);">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<?php slate_editor_css(); ?>

<?php slate_edit_open(['title_fallback' => $service['name']]); ?>

    <form method="post">
        <?= csrf_field() ?>

        <?php slate_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/service-rules.php'),
            'back_label' => __('booking_service_rule_all_services', 'All services'),
        ]); ?>

        <?php slate_edit_hero([
            'icon'        => 'tag',
            'title'       => $service['name'],
            'ref'         => (int)$service['id'],
            'status_text' => sprintf(__('booking_service_rule_extras_status', 'Booking+ extras · %s'), $service['payment_mode']),
            'status_tone' => 'on',
            'stats'       => [
                [__('booking_service_rule_stat_duration', 'Duration'),    (int)$service['duration_min'] . '<small>' . __('booking_service_rule_min_abbr', 'min') . '</small>'],
                [__('booking_service_rule_stat_min_advance', 'Min advance'), $statActive],
                [__('booking_service_rule_stat_prereq', 'Prereq'),      $statPrereq],
                [__('booking_service_rule_stat_auto_reply', 'Auto-reply'),  $statAuto],
            ],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'shield', 'eyebrow' => __('booking_service_rule_gates_eyebrow', 'Gates'), 'title' => __('booking_service_rule_gates_title', 'Booking gates')]); ?>
            <?php slate_edit_card_note(__('booking_service_rule_gates_note', 'Enforced on self-service (online) bookings only. Admin walk-ins bypass.')); ?>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="min_advance_days"><?= __('booking_service_rule_min_advance_label', 'Minimum advance (days)') ?></label>
                    <input type="number" id="min_advance_days" name="min_advance_days"
                           min="0" max="365" value="<?= (int)$cfg['min_advance_days'] ?>">
                    <div class="field-hint"><?= __('booking_service_rule_min_advance_hint', 'HSR-style delay. Set to <code>21</code> for a 3-week preparation period.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="prereq_service_id"><?= __('booking_service_rule_prereq_service_label', 'Required prior service') ?></label>
                    <select id="prereq_service_id" name="prereq_service_id">
                        <option value="0"><?= __('booking_service_rule_none_option', '— none —') ?></option>
                        <?php foreach ($otherServices as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"
                                <?= ((int)$cfg['prereq_service_id'] === (int)$o['id']) ? 'selected' : '' ?>>
                                <?= e($o['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-hint"><?= __('booking_service_rule_prereq_service_hint', 'Client must have a completed session of this type first.') ?></div>
                </div>
            </div>

            <div class="field-row field-row-2" style="margin-bottom:0;">
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="prereq_message"><?= __('booking_service_rule_prereq_message_label', 'Prereq refusal message') ?></label>
                    <input type="text" id="prereq_message" name="prereq_message" maxlength="500"
                           value="<?= e((string)$cfg['prereq_message']) ?>"
                           placeholder="<?= e(__('booking_service_rule_prereq_message_ph', 'This session requires a prior Discovery Call. Please book that first.')) ?>">
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="hsr_redirect_service_id"><?= __('booking_service_rule_redirect_label', 'Redirect to (when prereq missing)') ?></label>
                    <select id="hsr_redirect_service_id" name="hsr_redirect_service_id">
                        <option value="0"><?= __('booking_service_rule_stay_option', '— stay on this service —') ?></option>
                        <?php foreach ($otherServices as $o): ?>
                            <option value="<?= (int)$o['id'] ?>"
                                <?= ((int)$cfg['hsr_redirect_service_id'] === (int)$o['id']) ? 'selected' : '' ?>>
                                <?= e($o['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-hint"><?= __('booking_service_rule_redirect_hint', 'Optional. Widget acts on this in Phase 1.5.') ?></div>
                </div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'link', 'eyebrow' => __('booking_service_rule_links_eyebrow', 'Links'), 'title' => __('booking_service_rule_links_title', 'Links surfaced to the client')]); ?>
            <div class="field-row field-row-2" style="margin-bottom:0;">
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="prep_page_url"><?= __('booking_service_rule_prep_page_label', 'Preparation page URL') ?></label>
                    <input type="url" id="prep_page_url" name="prep_page_url" maxlength="500"
                           value="<?= e((string)$cfg['prep_page_url']) ?>"
                           placeholder="<?= e(__('booking_service_rule_prep_page_ph', 'https://your-site.com/prepare-hypnosis')) ?>">
                    <div class="field-hint"><?= __('booking_service_rule_prep_page_hint', 'Rendered as <code>{{prep_url}}</code> in the auto-response + reminders.') ?></div>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="whatsapp_url"><?= __('booking_service_rule_whatsapp_label', 'WhatsApp deeplink (per-service override)') ?></label>
                    <input type="url" id="whatsapp_url" name="whatsapp_url" maxlength="500"
                           value="<?= e((string)$cfg['whatsapp_url']) ?>"
                           placeholder="<?= e(__('booking_service_rule_whatsapp_ph', 'https://wa.me/33XXXXXXXXX')) ?>">
                    <div class="field-hint"><?= __('booking_service_rule_whatsapp_hint', 'Blank = use the global default from the Settings page.') ?></div>
                </div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'mail', 'eyebrow' => __('email', 'Email'), 'title' => __('booking_service_rule_email_title', 'Post-booking auto-response')]); ?>
            <?php slate_edit_card_note(__('booking_service_rule_email_note', 'Emails the client immediately after the booking confirms. Placeholders: <code>{{name}} {{service}} {{when}} {{ref}} {{prep_url}} {{whatsapp_url}} {{payment_note}}</code>.')); ?>

            <div class="field">
                <label class="field-label" for="auto_response_subject"><?= __('booking_service_rule_subject_label', 'Subject') ?></label>
                <input type="text" id="auto_response_subject" name="auto_response_subject" maxlength="200"
                       value="<?= e((string)$cfg['auto_response_subject']) ?>"
                       placeholder="<?= e(__('booking_service_rule_subject_ph', 'A little more about your booking — {{service}}')) ?>">
            </div>

            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="auto_response_body"><?= __('booking_service_rule_body_html_label', 'Body (HTML)') ?></label>
                <textarea id="auto_response_body" name="auto_response_body" rows="10"
                          style="min-height:200px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e((string)$cfg['auto_response_body']) ?></textarea>
                <div class="field-hint"><?= __('booking_service_rule_auto_response_hint', 'Leave blank to skip the auto-response (Booking\'s own confirmation still sends).') ?></div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'bell', 'eyebrow' => __('booking_service_rule_reminders_eyebrow', 'Reminders'), 'title' => __('booking_service_rule_reminders_title', 'Reminder body overrides (per lead time)')]); ?>
            <?php slate_edit_card_note(__('booking_service_rule_reminders_note', 'Reminders fire at 8 days, 1 day and 10 minutes before the session. Overrides apply once Booking core is patched with the <code>booking_reminder_body</code> filter (Phase 1 spec §2.8) — until then, Booking\'s generic template is used.')); ?>

            <div class="field">
                <label class="field-label" for="reminder_8day_body"><?= __('booking_service_rule_8day_label', '8-day reminder body') ?></label>
                <textarea id="reminder_8day_body" name="reminder_8day_body" rows="6"
                          style="min-height:130px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e((string)$cfg['reminder_8day_body']) ?></textarea>
                <div class="field-hint"><?= __('booking_service_rule_payment_link_hint', 'Include <code>{{payment_link}}</code> to embed the Stripe payment link.') ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="reminder_1day_body"><?= __('booking_service_rule_1day_label', 'Day-before reminder body') ?></label>
                <textarea id="reminder_1day_body" name="reminder_1day_body" rows="6"
                          style="min-height:130px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e((string)$cfg['reminder_1day_body']) ?></textarea>
                <div class="field-hint"><?= __('booking_service_rule_zoom_url_hint', 'Include <code>{{zoom_url}}</code> to embed the Zoom link.') ?></div>
            </div>

            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="reminder_10min_body"><?= __('booking_service_rule_10min_label', '10-minute reminder body') ?></label>
                <textarea id="reminder_10min_body" name="reminder_10min_body" rows="4"
                          style="min-height:100px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;"><?= e((string)$cfg['reminder_10min_body']) ?></textarea>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'link', 'eyebrow' => __('booking_service_rule_zoom', 'Zoom'), 'title' => __('booking_service_rule_zoom_title', 'Zoom link handling')]); ?>
            <div class="field-row field-row-2" style="margin-bottom:0;">
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="zoom_mode"><?= __('booking_service_rule_mode_label', 'Mode') ?></label>
                    <select id="zoom_mode" name="zoom_mode">
                        <?php $modes = [
                            'fallback_message' => __('booking_service_rule_zoom_mode_fallback', 'Fallback — client told link arrives by email'),
                            'manual'           => __('booking_service_rule_zoom_mode_manual', 'Manual — always use the recurring room below'),
                            'api'              => __('booking_service_rule_zoom_mode_api', 'API — real Zoom OAuth (Phase 1.5, not wired)'),
                        ]; foreach ($modes as $v => $label): ?>
                            <option value="<?= e($v) ?>" <?= ($cfg['zoom_mode'] === $v ? 'selected' : '') ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="zoom_join_url"><?= __('booking_service_rule_zoom_room_label', 'Recurring Zoom room URL (manual mode)') ?></label>
                    <input type="url" id="zoom_join_url" name="zoom_join_url" maxlength="500"
                           value="<?= e((string)$cfg['zoom_join_url']) ?>"
                           placeholder="<?= e(__('booking_service_rule_zoom_room_ph', 'https://us02web.zoom.us/j/...')) ?>">
                </div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<a href="' . e(plugin_url('booking', 'admin/service-rules.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>'
              . '<button type="submit" class="btn btn-primary">' . e(__('booking_service_rule_save_btn', 'Save')) . '</button>',
        ]); ?>

    </form>

<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
