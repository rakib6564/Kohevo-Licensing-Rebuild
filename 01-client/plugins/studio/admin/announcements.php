<?php
/**
 * Studio — admin Announcements.
 *
 * Targeted broadcast email plus the history of what was sent. Studio could
 * previously only send mail triggered by an enrolment or a payment; a studio
 * emails its families constantly for reasons no trigger covers — snow day,
 * hall change, recital call times.
 *
 * The audience is previewed before sending, including how many candidates were
 * dropped, because "41 families (3 have no email address)" tells an owner to go
 * and fix three records while a bare "41" does not.
 *
 * Every recipient gets their own message. Never a shared To or Bcc — a studio
 * email that leaks the parent list is a data-protection incident, and it is
 * the default outcome of the obvious implementation.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once dirname(__DIR__) . '/StudioMail.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_announcements', 'Studio · Announcements');
$currentNav = 'studio_announcements';

$selfUrl = plugin_url('studio', 'admin/announcements.php');

/** Build the audience spec from whatever the form posted or the URL carries. */
$specFrom = static function (array $src): array {
    $kind = (string) ($src['audience'] ?? 'all');
    return [
        'kind'    => $kind,
        'ref'     => (int) ($src['series_id'] ?? 0),
        'age_min' => (int) ($src['age_min'] ?? 0),
        'age_max' => (int) ($src['age_max'] ?? 99),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        studio_set_flash('error', __('csrf_failed', 'Security check failed.'));
        header('Location: ' . $selfUrl); exit;
    }
    try {
        $res = StudioAPI::sendAnnouncement(
            (string) ($_POST['subject'] ?? ''),
            (string) ($_POST['body'] ?? ''),
            $specFrom($_POST),
            (int) (Auth::user()['id'] ?? 0) ?: null
        );
        // A partial failure is reported as a warning, not a success. 38 of 41
        // delivered is something the studio must know about.
        studio_set_flash($res['failed'] === 0 ? 'success' : 'warning', sprintf(
            $res['failed'] === 0
                ? __('studio_ann_sent', 'Announcement sent to %d famil%s.')
                : __('studio_ann_partial', 'Announcement sent to %d famil%s — %3$d failed.'),
            $res['sent'], $res['sent'] === 1 ? 'y' : 'ies', $res['failed']
        ));
    } catch (\Throwable $e) {
        studio_set_flash('error', $e->getMessage());
    }
    header('Location: ' . $selfUrl); exit;
}

$spec    = $specFrom($_GET);
$preview = StudioAPI::announcementPreview($spec);
$summary = $preview['summary'];
$classes = StudioAPI::getActiveClassSeries();
$history = StudioAPI::listAnnouncements(30);
$viewing = (int) ($_GET['sent'] ?? 0);
$rcpts   = $viewing > 0 ? StudioAPI::announcementRecipients($viewing) : [];

$flash = studio_take_flash();
require dirname(__DIR__, 3) . '/admin/partials/header.php';
studio_ui_css();
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e((string) $flash['type']) ?>"><?= e((string) $flash['msg']) ?></div>
<?php endif; ?>

<?php if (!StudioMail::enabled()): ?>
    <div class="alert alert-warning">
        <?= __('studio_ann_notify_off',
            'Parent notifications are switched off in Settings. Announcements are sent by hand, so they will still go out — but automated mail will not.') ?>
    </div>
<?php endif; ?>

<div class="app-panel" style="margin-bottom:18px">
    <div class="card-header"><h2><?= __('studio_ann_new', 'New announcement') ?></h2></div>
    <div class="card-body">

        <form method="get" style="margin:0 0 18px">
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="audience"><?= __('studio_ann_audience', 'Send to') ?></label>
                    <select id="audience" name="audience" onchange="this.form.submit()">
                        <option value="all"    <?= $spec['kind'] === 'all'    ? 'selected' : '' ?>><?= __('studio_ann_a_all', 'All families') ?></option>
                        <option value="class"  <?= $spec['kind'] === 'class'  ? 'selected' : '' ?>><?= __('studio_ann_a_class_pick', 'One class') ?></option>
                        <option value="unpaid" <?= $spec['kind'] === 'unpaid' ? 'selected' : '' ?>><?= __('studio_ann_a_unpaid', 'Families with a balance') ?></option>
                        <option value="age"    <?= $spec['kind'] === 'age'    ? 'selected' : '' ?>><?= __('studio_ann_a_ageband', 'An age band') ?></option>
                    </select>
                </div>

                <?php if ($spec['kind'] === 'class'): ?>
                <div class="field">
                    <label class="field-label" for="series_id"><?= __('studio_class', 'Class') ?></label>
                    <select id="series_id" name="series_id" onchange="this.form.submit()">
                        <option value="0">— <?= __('studio_pick_class', 'Select a class') ?> —</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $spec['ref'] === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e((string) $c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($spec['kind'] === 'age'): ?>
                <div class="field">
                    <label class="field-label"><?= __('studio_ann_ages', 'Ages') ?></label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="number" name="age_min" min="0" max="99" value="<?= (int) $spec['age_min'] ?>" style="width:80px" onchange="this.form.submit()">
                        <span>–</span>
                        <input type="number" name="age_max" min="0" max="99" value="<?= (int) $spec['age_max'] ?>" style="width:80px" onchange="this.form.submit()">
                    </div>
                    <div class="field-hint"><?= __('studio_ann_ages_hint', 'Matches the age band on each class, not a date of birth.') ?></div>
                </div>
                <?php endif; ?>
            </div>
        </form>

        <div class="alert <?= $summary['deliverable'] > 0 ? 'alert-info' : 'alert-warning' ?>" style="margin-bottom:16px">
            <strong><?= sprintf(
                __('studio_ann_will_reach', 'Will reach %d famil%s'),
                $summary['deliverable'], $summary['deliverable'] === 1 ? 'y' : 'ies') ?></strong>
            <?php if ($summary['unreachable'] > 0): ?>
                — <?= sprintf(__('studio_ann_no_email', '%d have no email address on file'), $summary['unreachable']) ?>
            <?php endif; ?>
            <?php if ($summary['duplicates'] > 0): ?>
                · <?= sprintf(__('studio_ann_deduped', '%d duplicate(s) collapsed'), $summary['duplicates']) ?>
            <?php endif; ?>
            <?php if ($preview['recipients']): ?>
                <div style="margin-top:8px;font-size:13px;color:var(--muted)">
                    <?php
                    $names = array_slice(array_map(
                        static fn ($r) => $r['name'] !== '' ? $r['name'] : $r['email'],
                        $preview['recipients']), 0, 6);
                    echo e(implode(', ', $names));
                    if (count($preview['recipients']) > 6) {
                        echo ' + ' . (count($preview['recipients']) - 6) . ' more';
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="audience"  value="<?= e($spec['kind']) ?>">
            <input type="hidden" name="series_id" value="<?= (int) $spec['ref'] ?>">
            <input type="hidden" name="age_min"   value="<?= (int) $spec['age_min'] ?>">
            <input type="hidden" name="age_max"   value="<?= (int) $spec['age_max'] ?>">

            <div class="field">
                <label class="field-label" for="subject"><?= __('studio_ann_subject', 'Subject') ?></label>
                <input type="text" id="subject" name="subject" maxlength="180" required
                       placeholder="<?= e(__('studio_ann_subject_ph', 'e.g. Studio closed Monday — snow')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="body"><?= __('studio_ann_body', 'Message') ?></label>
                <textarea id="body" name="body" rows="8" required
                          placeholder="<?= e(__('studio_ann_body_ph', 'Hi {first_name}, …')) ?>"></textarea>
                <div class="field-hint">
                    <?= __('studio_ann_tokens', 'You can use {first_name} and {name}. Each family receives their own private email.') ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"
                    <?= $summary['deliverable'] === 0 ? 'disabled' : '' ?>
                    data-confirm="<?= e(sprintf(
                        __('studio_ann_confirm', 'Send this to %d famil%s?'),
                        $summary['deliverable'], $summary['deliverable'] === 1 ? 'y' : 'ies')) ?>">
                <?= __('studio_ann_send', 'Send announcement') ?>
            </button>
        </form>
    </div>
</div>

<?php if ($viewing > 0 && $rcpts): ?>
<div class="app-panel" style="margin-bottom:18px">
    <div class="card-header"><h2><?= __('studio_ann_who', 'Who it went to') ?></h2></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th><?= __('name', 'Name') ?></th><th><?= __('email', 'Email') ?></th>
                    <th><?= __('status', 'Status') ?></th><th><?= __('studio_sent_at', 'Sent') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rcpts as $r): ?>
                    <tr>
                        <td><?= e((string) ($r['name'] ?? '')) ?></td>
                        <td><?= e((string) $r['email']) ?></td>
                        <td><?= $r['status'] === 'sent'
                                ? '<span class="pill pill-green">' . e(__('studio_ann_ok', 'Sent')) . '</span>'
                                : '<span class="pill pill-amber">' . e((string) ($r['error'] ?: 'failed')) . '</span>' ?></td>
                        <td><?= e(substr((string) ($r['sent_at'] ?? ''), 0, 16)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="margin:12px 0 0"><a class="btn btn-sm" href="<?= e($selfUrl) ?>"><?= __('back', 'Back') ?></a></p>
    </div>
</div>
<?php endif; ?>

<div class="app-panel">
    <div class="card-header"><h2><?= __('studio_ann_history', 'Sent history') ?></h2></div>
    <div class="card-body">
        <?php if (!$history): ?>
            <p style="margin:0;color:var(--muted)">
                <?= __('studio_ann_none', 'Nothing sent yet. Announcements you send appear here with who received them.') ?>
            </p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th><?= __('studio_ann_subject', 'Subject') ?></th>
                    <th><?= __('studio_ann_audience', 'Sent to') ?></th>
                    <th><?= __('studio_ann_delivered', 'Delivered') ?></th>
                    <th><?= __('studio_sent_at', 'Sent') ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e((string) $h['subject']) ?></td>
                        <td><?= e((string) ($h['audience_label'] ?? '')) ?></td>
                        <td>
                            <?= (int) $h['sent_count'] ?>/<?= (int) $h['recipient_count'] ?>
                            <?php if ((int) $h['failed_count'] > 0): ?>
                                <span class="pill pill-amber"><?= (int) $h['failed_count'] ?> <?= __('studio_failed', 'failed') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(substr((string) ($h['sent_at'] ?? $h['created_at']), 0, 16)) ?></td>
                        <td><a class="btn btn-sm" href="<?= e($selfUrl) ?>?sent=<?= (int) $h['id'] ?>"><?= __('studio_ann_view', 'Recipients') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__, 3) . '/admin/partials/footer.php'; ?>
