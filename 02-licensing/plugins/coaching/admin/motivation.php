<?php
/**
 * Coaching — Motivation editor for one client.
 *
 * Practitioner-side: send challenges + exercises to a client. See what
 * they've completed. Trigger an end-of-program summary manually.
 *
 * URL: /plugins/coaching/admin/motivation.php?client=<customer_id>
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
Auth::requirePerm('coaching.manage_clients');
CoachingAPI::ensureSchema();

$cid = (int)($_GET['client'] ?? 0);
$tid = current_tenant_id();
$customer = $cid > 0 ? Database::row("SELECT * FROM customers WHERE id = ? AND tenant_id = ?", [$cid, $tid]) : null;
if (!$customer) {
    header('Location: ' . plugin_url('coaching', 'admin/clients.php'));
    exit;
}

$pageTitle  = __('cc_coaching_title', 'Coaching') . ' · ' . __('cc_motivation', 'Motivation') . ' · ' . $customer['name'];
$currentNav = 'coaching-clients';

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');
        if ($action === 'save_challenge') {
            CoachingAPI::saveChallenge([
                'id'               => (int)($_POST['id'] ?? 0),
                'customer_id'      => $cid,
                'kind'             => (string)($_POST['kind'] ?? 'challenge'),
                'title'            => (string)($_POST['title'] ?? ''),
                'description_html' => (string)($_POST['description_html'] ?? ''),
                'video_url'        => (string)($_POST['video_url'] ?? ''),
                'starts_at'        => (string)($_POST['starts_at'] ?? date('Y-m-d')),
                'ends_at'          => (string)($_POST['ends_at'] ?? ''),
            ]);
            $flash = ['type' => 'success', 'msg' => __('cc_saved', 'Saved.')];
        }
        elseif ($action === 'delete_challenge') {
            CoachingAPI::deleteChallenge((int)($_POST['id'] ?? 0));
            $flash = ['type' => 'success', 'msg' => __('cc_removed', 'Removed.')];
        }
        elseif ($action === 'generate_summary') {
            CoachingAPI::generateSummary($cid);
            $flash = ['type' => 'success', 'msg' => __('cc_summary_generated', 'Summary generated. The client sees it in their program menu.')];
        }
    }
}

$active    = CoachingAPI::listChallenges($cid, true);
$completed = Database::rows(
    "SELECT * FROM coaching_challenge WHERE tenant_id = ? AND customer_id = ? AND completed_at IS NOT NULL ORDER BY completed_at DESC LIMIT 30",
    [$tid, $cid]);
$summary   = CoachingAPI::getSummary($cid);

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching'),  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => __('cc_program_clients', 'Program clients'), 'href' => plugin_url('coaching', 'admin/clients.php')],
    ['label' => $customer['name'], 'href' => plugin_url('coaching', 'admin/client.php') . '?id=' . (int)$cid],
    ['label' => __('cc_motivation', 'Motivation')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('cc_motivation', 'Motivation') ?> · <?= e($customer['name']) ?></h1>
        <p class="text-muted"><?= __('cc_motivation_sub', 'Challenges, exercises, and the end-of-program summary.') ?></p>
    </div>
    <a href="<?= e(plugin_url('coaching', 'admin/client.php')) ?>?id=<?= (int)$cid ?>" class="btn btn-ghost"><?= __('cc_client_detail_link', '← Client detail') ?></a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-3);"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-3);">
    <h3 style="margin-top:0;"><?= __('cc_new_challenge_h3', 'New challenge or exercise') ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_challenge">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px;">
            <div class="field"><label class="field-label" for="title"><?= __('title', 'Title') ?></label>
                <input type="text" id="title" name="title" required maxlength="200" placeholder="<?= e(__('cc_challenge_placeholder', '7-day mindful eating challenge')) ?>">
            </div>
            <div class="field"><label class="field-label" for="kind"><?= __('cc_kind_label', 'Kind') ?></label>
                <select id="kind" name="kind">
                    <option value="challenge"><?= __('cc_kind_challenge', 'Challenge') ?></option>
                    <option value="exercise"><?= __('cc_kind_exercise', 'Exercise') ?></option>
                </select>
            </div>
            <div class="field"><label class="field-label" for="starts_at"><?= __('starts', 'Starts') ?></label>
                <input type="date" id="starts_at" name="starts_at" value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="field"><label class="field-label" for="ends_at"><?= __('cc_ends_optional', 'Ends (optional)') ?></label>
                <input type="date" id="ends_at" name="ends_at">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="description_html"><?= __('cc_description_html_label', 'Description (HTML allowed)') ?></label>
            <textarea id="description_html" name="description_html" rows="4"></textarea>
        </div>
        <div class="field">
            <label class="field-label" for="video_url"><?= __('cc_video_url_label', 'Video URL (optional)') ?></label>
            <input type="url" id="video_url" name="video_url" maxlength="500" placeholder="https://youtu.be/…">
        </div>
        <div style="text-align:right;">
            <button class="btn btn-primary"><?= __('cc_send_to_client', 'Send to client') ?></button>
        </div>
    </form>
</div>

<?php if ($active): ?>
    <div class="card" style="padding:0;margin-bottom:var(--space-3);">
        <h3 style="margin:0;padding:var(--space-4) var(--space-4) 0;"><?= __('cc_active_h3', 'Active') ?></h3>
        <div class="data-list" data-single-open>
            <?php foreach ($active as $ch):
                $kindLabel = $ch['kind'] === 'exercise' ? __('cc_kind_exercise', 'Exercise') : __('cc_kind_challenge', 'Challenge');
                $when = I18n::localDate('j M', strtotime($ch['starts_at']))
                      . (!empty($ch['ends_at']) ? ' → ' . I18n::localDate('j M', strtotime($ch['ends_at'])) : '');

                ob_start(); ?>
                <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('cc_confirm_remove', 'Remove?')) ?>');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete_challenge">
                    <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                    <button class="btn btn-sm btn-danger"><?= __('remove', 'Remove') ?></button>
                </form>
                <?php $rowActions = ob_get_clean();

                slate_data_row([
                    'title'        => (string)$ch['title'],
                    'meta'         => $when,
                    'badge'        => [$kindLabel, $ch['kind'] === 'exercise' ? 'success' : 'info'],
                    'actions'      => $rowActions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    </div>
<?php endif; ?>

<?php if ($completed): ?>
    <div class="card" style="padding:0;margin-bottom:var(--space-3);">
        <h3 style="margin:0;padding:var(--space-4) var(--space-4) 0;"><?= __('cc_completed_h3', 'Completed by the client') ?></h3>
        <div class="data-list" data-single-open>
            <?php foreach ($completed as $ch):
                $completedWhen = I18n::localDate('j M · H:i', strtotime($ch['completed_at']));
                $note = trim((string)($ch['client_note'] ?? ''));

                slate_data_row([
                    'title'        => (string)$ch['title'],
                    'meta'         => $completedWhen,
                    'detail'       => $note !== '' ? [['label' => __('cc_client_note_label', 'Client note'), 'html' => '&quot;' . e($note) . '&quot;']] : [],
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    </div>
<?php endif; ?>

<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-3);">
    <h3 style="margin-top:0;"><?= __('cc_end_of_program_summary_h3', 'End-of-program summary') ?></h3>
    <?php if ($summary): ?>
        <p class="text-muted" style="font-size:13px;">
            <?= sprintf(__('cc_summary_last_generated', 'Last generated %s.'), e(I18n::localDate('j M Y · H:i', strtotime($summary['generated_at'])))) ?>
            <?= sprintf(__('cc_summary_covers', 'Covers %s → %s.'), e(I18n::localDate('j M', strtotime($summary['period_start']))), e(I18n::localDate('j M Y', strtotime($summary['period_end'])))) ?>
        </p>
        <details style="margin-top:8px;">
            <summary style="cursor:pointer;color:var(--coach-brand);"><?= __('cc_preview_summary', 'Preview what the client will see') ?></summary>
            <pre style="background:#f8fafc;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px;line-height:1.6;margin-top:8px;"><?= e(json_encode(json_decode((string)$summary['summary_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
    <?php else: ?>
        <p class="text-muted" style="font-size:14px;"><?= __('cc_no_summary_yet', 'No summary yet. The cron auto-generates one 3 days before membership expiry; you can also trigger it manually.') ?></p>
    <?php endif; ?>
    <form method="post" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="generate_summary">
        <button class="btn btn-primary"><?= $summary ? __('cc_regenerate_summary', 'Regenerate summary') : __('cc_generate_summary_now', 'Generate summary now') ?></button>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
