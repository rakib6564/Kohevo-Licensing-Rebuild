<?php
/**
 * Studio — admin Fees.
 *
 * The collection side of studio_fees: costumes, tights and the recital
 * performance fee. Tuition is not here — it lives on the enrolment and is
 * settled from the Enrollments page.
 *
 * The ledger existed and was populated before this page did, which meant a
 * studio could raise $1,825 of obligations and have no way to see or collect
 * them. This is that missing surface.
 *
 * Marking paid goes through StudioAPI::markFeePaid(), which is idempotent on
 * the transition and fires studio_fee_paid only on the way from pending to
 * paid — so this page and a future Stripe return cannot double-fire a receipt.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_fees', 'Studio · Fees');
$currentNav = 'studio_fees';

$selfUrl = plugin_url('studio', 'admin/fees.php');
$money   = static fn (int $c): string => '$' . number_format($c / 100, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        studio_set_flash('error', __('csrf_failed', 'Security check failed.'));
        header('Location: ' . $selfUrl); exit;
    }
    $do = (string) ($_POST['_do'] ?? '');
    $n  = 0;

    try {
        // Validation, family derivation and the insert all live in
        // StudioAPI::raiseAdHocFee() — money should be raised in one place,
        // and a rule that lives in a page template cannot be tested.
        if ($do === 'add') {
            $label  = trim((string) ($_POST['label'] ?? ''));
            $amount = (int) round(((float) ($_POST['amount'] ?? 0)) * 100);

            StudioAPI::raiseAdHocFee(
                (int) ($_POST['student_id'] ?? 0),
                $label,
                $amount,
                [
                    'kind'      => (string) ($_POST['kind'] ?? 'other'),
                    'series_id' => (int) ($_POST['series_id'] ?? 0),
                    'due_date'  => (string) ($_POST['due_date'] ?? ''),
                ]
            );

            studio_set_flash('success', sprintf(
                __('studio_fee_added', 'Raised %s — %s.'), $money($amount), $label));
            header('Location: ' . $selfUrl); exit;
        }

        if (str_starts_with($do, 'paid_')) {
            $id  = (int) substr($do, 5);
            $row = Database::row("SELECT amount_cents FROM studio_fees WHERE tenant_id = ? AND id = ?",
                                 [current_tenant_id(), $id]);
            if ($row && StudioAPI::markFeePaid($id, (int) $row['amount_cents'], 'offline')) { $n = 1; }
        } elseif (str_starts_with($do, 'void_')) {
            $n = StudioAPI::voidFee((int) substr($do, 5)) ? 1 : 0;
        } elseif ($do === 'bulk_paid' || $do === 'bulk_void') {
            foreach ((array) ($_POST['ids'] ?? []) as $raw) {
                $id  = (int) $raw;
                if ($id <= 0) { continue; }
                if ($do === 'bulk_void') { $n += StudioAPI::voidFee($id) ? 1 : 0; continue; }
                $row = Database::row("SELECT amount_cents FROM studio_fees WHERE tenant_id = ? AND id = ?",
                                     [current_tenant_id(), $id]);
                if ($row && StudioAPI::markFeePaid($id, (int) $row['amount_cents'], 'offline')) { $n++; }
            }
        }
        studio_set_flash('success', sprintf(__('studio_fees_updated', '%d fee(s) updated.'), $n));
    } catch (\Throwable $e) {
        studio_set_flash('error', $e->getMessage());
    }
    header('Location: ' . $selfUrl); exit;
}

$fees = StudioAPI::listFees();

// For the "add a fee" form. Inactive series are still offered: a fee can be
// raised against a class that has finished, which is the usual case for a
// costume surcharge settled after the season.
$families = StudioAPI::getFamiliesWithMembers();
$series   = StudioAPI::getClassSeriesWithStats();

$cPending = $cPaid = $cVoid = 0;
$sumPending = $sumPaid = 0;
foreach ($fees as $f) {
    switch ((string) $f['status']) {
        case 'paid':  $cPaid++;  $sumPaid    += (int) $f['amount_cents']; break;
        case 'void':  $cVoid++;  break;
        default:      $cPending++; $sumPending += (int) $f['amount_cents'];
    }
}

$flash = studio_take_flash();
require dirname(__DIR__, 3) . '/admin/partials/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e((string) $flash['type']) ?>"><?= e((string) $flash['msg']) ?></div>
<?php endif; ?>

<div class="app-panel" style="margin-bottom:18px">
    <div class="card-header"><h2><?= __('studio_fees', 'Fees') ?></h2></div>
    <div class="card-body">
        <p style="margin:0 0 14px;color:var(--muted);font-size:14px">
            <?= __('studio_fees_admin_sub',
                'Costumes, tights and recital fees. Tuition is settled from Enrollments.') ?>
        </p>
        <div class="studio-kpis" style="display:flex;gap:14px;flex-wrap:wrap">
            <div><strong style="font-size:22px"><?= e($money($sumPending)) ?></strong>
                 <div style="font-size:12px;color:var(--muted)"><?= __('studio_outstanding', 'Outstanding') ?></div></div>
            <div><strong style="font-size:22px"><?= e($money($sumPaid)) ?></strong>
                 <div style="font-size:12px;color:var(--muted)"><?= __('studio_collected', 'Collected') ?></div></div>
        </div>
    </div>
</div>

<?php
// Sits outside studio_ui_list_start(), which opens its own <form> for the bulk
// actions below — nesting one form in another silently drops this one's fields.
$hasStudents = false;
foreach ($families as $f) {
    if (!empty($f['students'])) { $hasStudents = true; break; }
}
?>

<details class="app-panel" style="margin-bottom:18px">
    <summary style="cursor:pointer;padding:16px 18px;font-weight:700;font-size:15px;list-style:none">
        <?= __('studio_fee_add', 'Add a fee') ?>
        <span style="font-weight:400;color:var(--muted);font-size:13px">
            — <?= __('studio_fee_add_hint', 'competition costumes, crystals, anything the generators cannot know about') ?>
        </span>
    </summary>
    <div class="card-body" style="border-top:1px solid var(--line, #ECEEF1)">
        <?php if (!$hasStudents): ?>
            <p style="margin:0;color:var(--muted);font-size:14px">
                <?= __('studio_fee_add_no_students',
                    'No dancers on file yet. Add a family and a dancer first, then fees can be raised against them.') ?>
            </p>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_do" value="add">

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="fee_student"><?= __('studio_student', 'Dancer') ?></label>
                    <select id="fee_student" name="student_id" required>
                        <option value=""><?= __('studio_pick_student', '— Select a student —') ?></option>
                        <?php foreach ($families as $f):
                            if (empty($f['students'])) { continue; }
                            $group = $f['parent_name'] ?: (__('studio_family', 'Family') . ' #' . $f['id']);
                        ?>
                            <optgroup label="<?= e($group) ?>">
                                <?php foreach ($f['students'] as $st): ?>
                                    <option value="<?= (int) $st['id'] ?>"><?= e($st['display_name'] ?: ('#' . $st['id'])) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-hint"><?= __('studio_fee_add_family_hint', 'The family is billed; the dancer is who it is for.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="fee_series"><?= __('studio_class', 'Class') ?></label>
                    <select id="fee_series" name="series_id">
                        <option value=""><?= __('studio_fee_no_class', '— Not class-specific —') ?></option>
                        <?php foreach ($series as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= e((string) $s['name'])
                                . ((int) $s['is_active'] === 1 ? '' : ' ' . __('studio_inactive_suffix', '(inactive)')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="fee_label"><?= __('studio_fee_label', 'What it is for') ?></label>
                    <input type="text" id="fee_label" name="label" maxlength="160" required
                           placeholder="<?= e(__('studio_fee_label_ph', 'Competition crystals')) ?>">
                    <div class="field-hint"><?= __('studio_fee_label_hint', 'This is what the parent sees on their statement.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="fee_kind"><?= __('studio_fee_kind', 'Kind') ?></label>
                    <select id="fee_kind" name="kind">
                        <option value="costume"><?= __('studio_costume', 'Costume') ?></option>
                        <option value="tights"><?= __('studio_tights', 'Tights') ?></option>
                        <option value="recital"><?= __('studio_recital', 'Recital') ?></option>
                        <option value="registration"><?= __('studio_registration', 'Registration') ?></option>
                        <option value="other" selected><?= __('other', 'Other') ?></option>
                    </select>
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="fee_amount"><?= __('studio_amount', 'Amount') ?></label>
                    <input type="number" id="fee_amount" name="amount" step="0.01" min="0.01" required
                           placeholder="0.00">
                </div>
                <div class="field">
                    <label class="field-label" for="fee_due"><?= __('studio_due_date', 'Due date') ?></label>
                    <input type="date" id="fee_due" name="due_date">
                    <div class="field-hint"><?= __('studio_fee_due_hint', 'Optional. Reminders only chase fees that have one.') ?></div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= __('studio_fee_raise', 'Raise fee') ?></button>
        </form>
        <?php endif; ?>
    </div>
</details>

<?php
studio_ui_list_start([
    ['value' => 'pending', 'label' => __('studio_outstanding', 'Outstanding'), 'count' => $cPending],
    ['value' => 'paid',    'label' => __('studio_paid', 'Paid'),               'count' => $cPaid],
    ['value' => 'void',    'label' => __('studio_void', 'Void'),               'count' => $cVoid],
    ['value' => 'all',     'label' => __('all', 'All'),                        'count' => count($fees)],
], __('studio_search_fees', 'Search parent, dancer or class…'), [
    ['value' => 'paid', 'label' => __('studio_mark_paid', 'Mark paid'),
     'confirm' => __('studio_confirm_bulk_fee_paid', 'Mark the selected fees as paid (offline)?')],
    ['value' => 'void', 'label' => __('studio_void', 'Void'), 'danger' => true,
     'confirm' => __('studio_confirm_bulk_fee_void', 'Void the selected fees? Paid ones are left alone.')],
]);

$kindLabel = [
    'costume'      => __('studio_costume', 'Costume'),
    'tights'       => __('studio_tights', 'Tights'),
    'recital'      => __('studio_recital', 'Recital'),
    'registration' => __('studio_registration', 'Registration'),
    'other'        => __('other', 'Other'),
];

foreach ($fees as $f):
    $status  = (string) $f['status'];
    $who     = (string) ($f['student_name'] ?? '') ?: (string) ($f['parent_name'] ?? '') ?: ('#' . $f['family_id']);
    $parent  = (string) ($f['parent_name'] ?? '—');
    $due     = $f['due_date'] ? date('j M Y', strtotime((string) $f['due_date'])) : '—';
    $kind    = $kindLabel[(string) $f['kind']] ?? (string) $f['kind'];

    $actions = '';
    if ($status === 'pending') {
        $actions .= '<button type="submit" name="_do" value="paid_' . (int) $f['id'] . '" class="btn btn-sm btn-primary" data-confirm="'
                  . e(__('studio_confirm_fee_paid', 'Mark this fee as paid (offline)?')) . '">'
                  . e(__('studio_mark_paid', 'Mark paid')) . '</button> ';
        $actions .= '<button type="submit" name="_do" value="void_' . (int) $f['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
                  . e(__('studio_confirm_fee_void', 'Void this fee? It stays visible but stops being chased.')) . '">'
                  . e(__('studio_void', 'Void')) . '</button>';
    }

    $badge = match ($status) {
        'paid' => [__('studio_paid', 'Paid'), 'active'],
        'void' => [__('studio_void', 'Void'), 'muted'],
        default => [__('studio_outstanding', 'Outstanding'), 'accent'],
    };

    studio_ui_row([
        '_id'          => (int) $f['id'],
        'avatar'       => $who,
        'avatar_color' => $status === 'paid' ? 'success' : ($status === 'void' ? 'muted' : 'accent'),
        'title'        => $who,
        'meta'         => (string) $f['label'],
        'value'        => $money((int) $f['amount_cents']),
        'badge'        => $badge,
        'detail'       => [
            __('studio_family', 'Family')        => $parent,
            __('studio_kind', 'Type')            => $kind,
            __('studio_class', 'Class')          => (string) ($f['series_name'] ?? '—') ?: '—',
            __('studio_due', 'Due')              => $due,
            __('studio_instalment', 'Instalment') => ((int) $f['instalment_of']) > 1
                ? sprintf('%d of %d', (int) $f['instalment_no'], (int) $f['instalment_of'])
                : '—',
            __('studio_payment', 'Payment')      => $status === 'paid'
                ? $money((int) $f['paid_cents']) . ' · ' . (string) ($f['paid_method'] ?? '')
                : '—',
        ],
        'actions'      => $actions,
        '_filter'      => $status,
        '_search'      => $who . ' ' . $parent . ' ' . $f['label'] . ' ' . (string) ($f['series_name'] ?? ''),
    ]);
endforeach;

studio_ui_list_end(count($fees), __('studio_no_fees', 'No fees raised yet'), [
    'icon' => 'tag',
    'sub'  => __('studio_no_fees_sub',
        'Costume, tights and recital fees appear here once a season is billed. Run tools/raise_season_fees.php to raise them.'),
]);

require dirname(__DIR__, 3) . '/admin/partials/footer.php';
